<?php

namespace LaravelSmartOCR\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LaravelSmartOCR\Data\DocumentInput;
use LaravelSmartOCR\Exceptions\DocumentParserException;
use LaravelSmartOCR\Models\ProcessedDocument;
use LaravelSmartOCR\Services\DocumentValidator;
use LaravelSmartOCR\Services\RemoteDocumentResolver;
use LaravelSmartOCR\Services\TemporaryDocumentManager;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentParser
{
    protected $app;
    protected OCRManager $ocrManager;
    protected TemplateManager $templateManager;
    protected AICleanupService $aiCleanup;
    protected DocumentValidator $validator;
    protected RemoteDocumentResolver $remoteResolver;

    public function __construct($app)
    {
        $this->app            = $app;
        $this->ocrManager     = $app->make('smart-ocr');
        $this->templateManager = $app->make('smart-ocr.templates');
        $this->aiCleanup      = $app->make('smart-ocr.ai-cleanup');
        $this->validator      = $app->make('smart-ocr.validator');
        $this->remoteResolver = $app->make('smart-ocr.remote-resolver');
    }

    public function parse($document, array $options = []): array
    {
        $startTime  = microtime(true);
        $tempManager = new TemporaryDocumentManager(
            $this->app['config']->get('smart-ocr.storage.temp_path')
        );

        try {
            $input        = $this->resolveInput($document, $tempManager);
            $documentPath = $input->localPath();
            
            $rawExtraction = $this->ocrManager->extract($documentPath, $options);
            
            $template = null;
            if (isset($options['template_id'])) {
                $template = $this->templateManager->applyTemplate($rawExtraction, $options['template_id']);
            } elseif ($options['auto_detect_template'] ?? true) {
                $detectedTemplate = $this->templateManager->findTemplateByContent($rawExtraction['text']);
                if ($detectedTemplate) {
                    $template = $this->templateManager->applyTemplate($rawExtraction, $detectedTemplate->id);
                }
            }
            
            $structured = $template ?? $this->structureExtraction($rawExtraction, $options);
            
            if ($options['use_ai_cleanup'] ?? false) {
                $structured = $this->aiCleanup->clean($structured, $options);
            }
            
            if ($options['save_to_database'] ?? false) {
                $this->saveToDatabase($structured, $document, $options);
            }
            
            return [
                'success' => true,
                'data' => $structured,
                'metadata' => [
                    'processing_time' => microtime(true) - $startTime,
                    'document_type' => $options['document_type'] ?? $this->detectDocumentType($structured),
                    'template_used' => $template['template_name'] ?? null,
                    'ai_cleanup_used' => $options['use_ai_cleanup'] ?? false,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'metadata' => [
                    'processing_time' => microtime(true) - $startTime,
                ],
            ];
        } finally {
            // Always clean up temp files — success and failure paths both reach here
            $tempManager->cleanup();
            if (isset($input)) {
                $input->cleanup();
            }
        }
    }

    public function parseBatch(array $documents, array $options = []): array
    {
        $results = [];
        
        foreach ($documents as $document) {
            $results[] = $this->parse($document, $options);
        }
        
        return $results;
    }

    public function parseWithWorkflow($document, string $workflow): array
    {
        $workflowConfig = config("smart-ocr.workflows.{$workflow}");
        
        if (!$workflowConfig) {
            throw new DocumentParserException("Workflow '{$workflow}' not found");
        }
        
        $options = $workflowConfig['options'] ?? [];
        $result = $this->parse($document, $options);
        
        if (isset($workflowConfig['post_processors'])) {
            foreach ($workflowConfig['post_processors'] as $processor) {
                $result = $this->applyPostProcessor($result, $processor);
            }
        }
        
        if (isset($workflowConfig['validators'])) {
            $result['validation'] = $this->validateResult($result, $workflowConfig['validators']);
        }
        
        return $result;
    }

    public function extractMetadata($document): array
    {
        $metadata = [
            'file_name' => basename($document),
            'file_size' => filesize($document),
            'mime_type' => mime_content_type($document),
            'created_at' => date('Y-m-d H:i:s', filectime($document)),
            'modified_at' => date('Y-m-d H:i:s', filemtime($document)),
        ];
        
        $extension = strtolower(pathinfo($document, PATHINFO_EXTENSION));
        
        if ($extension === 'pdf') {
            $metadata = array_merge($metadata, $this->extractPdfMetadata($document));
        }
        
        return $metadata;
    }

    /**
     * Normalise any supported input type into a DocumentInput and validate it.
     * Registers any temp files with $tempManager so cleanup() catches them.
     */
    protected function resolveInput($document, TemporaryDocumentManager $tempManager): DocumentInput
    {
        if ($document instanceof UploadedFile) {
            $input = DocumentInput::fromUploadedFile($document);
            $this->validator->validate($input);
            return $input;
        }

        if (is_string($document) && filter_var($document, FILTER_VALIDATE_URL)) {
            $allowRemote = $this->app['config']->get('smart-ocr.remote_urls.allow_remote_urls', false);
            if (! $allowRemote) {
                throw new DocumentParserException(
                    "Remote URL processing is disabled. Set smart-ocr.remote_urls.allow_remote_urls to true to enable it."
                );
            }
            $input = $this->remoteResolver->resolve($document);
            $tempManager->track($input->localPath());
            $this->validator->validate($input);
            return $input;
        }

        if (is_string($document) && file_exists($document)) {
            $input = DocumentInput::fromPath($document);
            $this->validator->validate($input);
            return $input;
        }

        throw new DocumentParserException("Invalid document input: expected a file path, UploadedFile, or URL.");
    }

    /**
     * Parse a number string into a plain decimal string (no thousands separators).
     *
     * Locale conventions:
     *   en  — comma=thousands, dot=decimal   e.g. "1,234.56" → "1234.56"
     *   eu  — dot=thousands,  comma=decimal  e.g. "1.234,56" → "1234.56"
     *   in  — Indian grouping with dot=dec   e.g. "1,23,456.78" → "123456.78"
     */
    public function parseNumber(string $raw, string $locale = ''): string
    {
        if ($locale === '') {
            $locale = (string) config('smart-ocr.processing.locale', 'en');
        }

        $raw = trim($raw);

        if ($locale === 'eu') {
            // dot = thousands separator, comma = decimal point
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            // en / in: comma = thousands separator, dot = decimal point
            $raw = str_replace(',', '', $raw);
        }

        // Validate it looks numeric
        if (! is_numeric($raw)) {
            return '0';
        }

        // Normalise to plain decimal string (no trailing .0 for integers)
        if (strpos($raw, '.') !== false) {
            $raw = rtrim(rtrim($raw, '0'), '.');
            if ($raw === '' || $raw === '-') {
                return '0';
            }
            // Ensure at least two decimal places were preserved as-is
            return $raw;
        }

        return $raw;
    }

    /**
     * Parse a date string into a structured array.
     *
     * @return array{iso: string, original: string}|null
     */
    public function parseDate(string $raw, ?bool $dayFirst = null): ?array
    {
        if ($dayFirst === null) {
            $dayFirst = (bool) config('smart-ocr.processing.date_day_first', true);
        }

        $raw = trim($raw);

        // ISO format yyyy-mm-dd — unambiguous
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $raw, $m)) {
            $iso = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return ['iso' => $iso, 'original' => $raw];
            }
            return null;
        }

        // dd/mm/yyyy or mm/dd/yyyy (ambiguous — use $dayFirst)
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})$/', $raw, $m)) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += ($year >= 70 ? 1900 : 2000);
            }
            if ($dayFirst) {
                $day   = (int) $m[1];
                $month = (int) $m[2];
            } else {
                $month = (int) $m[1];
                $day   = (int) $m[2];
            }
            if (checkdate($month, $day, $year)) {
                $iso = sprintf('%04d-%02d-%02d', $year, $month, $day);
                return ['iso' => $iso, 'original' => $raw];
            }
            return null;
        }

        // Month name formats: "Jan 02, 2026" or "02 Jan 2026"
        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];
        $monthPattern = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';
        if (preg_match('/^(' . $monthPattern . ')[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})$/i', $raw, $m)) {
            $month = $months[strtolower(substr($m[1], 0, 3))];
            $day   = (int) $m[2];
            $year  = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                return ['iso' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'original' => $raw];
            }
        }
        if (preg_match('/^(\d{1,2})\s+(' . $monthPattern . ')[a-z]*\.?\s+(\d{4})$/i', $raw, $m)) {
            $day   = (int) $m[1];
            $month = $months[strtolower(substr($m[2], 0, 3))];
            $year  = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                return ['iso' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'original' => $raw];
            }
        }

        return null;
    }

    /**
     * Map a currency symbol or ISO code to an ISO 4217 currency code.
     */
    protected function detectCurrency(string $symbol): string
    {
        $map = [
            '$'   => 'USD',
            '€'   => 'EUR',
            '£'   => 'GBP',
            '¥'   => 'JPY',
            '₹'   => 'INR',
            'USD' => 'USD',
            'EUR' => 'EUR',
            'GBP' => 'GBP',
            'JPY' => 'JPY',
            'INR' => 'INR',
            'CAD' => 'CAD',
            'AUD' => 'AUD',
        ];
        return $map[trim($symbol)] ?? 'USD';
    }

    protected function structureExtraction(array $extraction, array $options): array
    {
        $ocrConfidence = null;
        if (isset($extraction['_ocr_result']) && $extraction['_ocr_result'] instanceof \LaravelSmartOCR\Results\OcrResult) {
            $ocrConfidence = $extraction['_ocr_result']->confidence();
        }

        $structure = [
            'raw_text' => $extraction['text'],
            'confidence' => $ocrConfidence,
            'fields' => [],
        ];
        
        if (isset($extraction['bounds']) && !empty($extraction['bounds'])) {
            $structure['layout'] = $this->analyzeLayout($extraction['bounds']);
        }
        
        $documentType = $options['document_type'] ?? $this->detectDocumentType($extraction);
        $structure['document_type'] = $documentType;
        
        $structure['fields'] = $this->extractCommonFields($extraction['text'], $documentType);
        
        return $structure;
    }

    protected function extractCommonFields(string $text, ?string $documentType): array
    {
        $fields = [];
        
        $patterns = $this->getFieldPatterns($documentType);
        
        foreach ($patterns as $fieldName => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $fields[$fieldName] = [
                    'value' => trim($matches[1] ?? $matches[0]),
                    'confidence' => null,
                ];
            }
        }
        
        $fields['dates'] = $this->extractDates($text);
        $fields['amounts'] = $this->extractAmounts($text);
        $fields['emails'] = $this->extractEmails($text);
        $fields['phones'] = $this->extractPhoneNumbers($text);
        $fields['urls'] = $this->extractUrls($text);
        
        // Enhanced line item extraction for invoices
        if ($documentType === 'invoice' || strpos(strtolower($text), 'invoice') !== false) {
            $fields['line_items'] = $this->extractAdvancedLineItems($text);
            $fields['totals'] = $this->extractInvoiceTotals($text);
        }
        
        return array_filter($fields);
    }

    protected function getFieldPatterns(?string $documentType): array
    {
        $commonPatterns = [
            'invoice_number' => '/(?:invoice|inv|bill)\s*#?\s*:?\s*([A-Z0-9\-]+)/i',
            'po_number' => '/(?:po|purchase\s*order)\s*#?\s*:?\s*([A-Z0-9\-]+)/i',
            'tax_id' => '/(?:tax\s*id|vat|gst|ein)\s*:?\s*([A-Z0-9\-]+)/i',
            'account_number' => '/(?:account|acct)\s*#?\s*:?\s*([0-9\-]+)/i',
        ];
        
        $typePatterns = [
            'invoice' => [
                'due_date' => '/(?:due\s*date|payment\s*due)\s*:?\s*([0-9\/\-\s\w]+)/i',
                'terms' => '/(?:terms|payment\s*terms)\s*:?\s*([^\n]+)/i',
            ],
            'receipt' => [
                'receipt_number' => '/(?:receipt|transaction)\s*#?\s*:?\s*([A-Z0-9\-]+)/i',
                'cashier' => '/(?:cashier|served\s*by)\s*:?\s*([^\n]+)/i',
            ],
            'contract' => [
                'contract_number' => '/(?:contract|agreement)\s*#?\s*:?\s*([A-Z0-9\-]+)/i',
                'effective_date' => '/(?:effective\s*date|start\s*date)\s*:?\s*([0-9\/\-\s\w]+)/i',
            ],
        ];
        
        return array_merge($commonPatterns, $typePatterns[$documentType] ?? []);
    }

    protected function extractDates(string $text): array
    {
        $dates = [];
        
        $patterns = [
            '/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/',
            '/\b\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}\b/',
            '/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4}\b/i',
            '/\b\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{4}\b/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $match) {
                    $timestamp = strtotime($match);
                    if ($timestamp !== false) {
                        $dates[] = [
                            'original' => $match,
                            'normalized' => date('Y-m-d', $timestamp),
                            'timestamp' => $timestamp,
                        ];
                    }
                }
            }
        }
        
        return $dates;
    }

    protected function extractAmounts(string $text): array
    {
        $amounts = [];

        // Pattern: optional currency symbol/code before or after the number
        // Supports: $, €, £, ¥, ₹ and ISO codes USD EUR GBP JPY INR CAD AUD
        $currencyBefore = '(?P<sym>[$€£¥₹]|USD|EUR|GBP|JPY|INR|CAD|AUD)\s*';
        $currencyAfter  = '\s*(?P<sym2>USD|EUR|GBP|JPY|INR|CAD|AUD)';
        $numberPat      = '(?P<num>[0-9]{1,3}(?:[,\.][0-9]{3})*(?:[,\.][0-9]{1,2})?|[0-9]+(?:\.[0-9]{1,2})?)';

        $patterns = [
            '/' . $currencyBefore . $numberPat . '/u',
            '/' . $numberPat . $currencyAfter . '/u',
            '/(?:total|amount|price|cost|fee|charge)\s*:?\s*(?P<sym>[$€£¥₹])?\s*(?P<num>[0-9][0-9,\.]*)/iu',
        ];

        $seen = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $raw      = $match[0];
                    $numStr   = $match['num'] ?? '';
                    $symStr   = $match['sym'] ?? ($match['sym2'] ?? '');

                    if ($numStr === '') {
                        continue;
                    }

                    $decimalStr = $this->parseNumber($numStr);
                    if ($decimalStr === '0' || ! is_numeric($decimalStr)) {
                        continue;
                    }

                    $key = $decimalStr . '|' . $symStr;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $amounts[] = [
                        'amount'   => $decimalStr,
                        'currency' => $symStr !== '' ? $this->detectCurrency($symStr) : 'USD',
                        'raw'      => trim($raw),
                    ];
                }
            }
        }

        return $amounts;
    }

    protected function extractEmails(string $text): array
    {
        $emails = [];
        
        if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $matches)) {
            foreach ($matches[0] as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = strtolower($email);
                }
            }
        }
        
        return array_unique($emails);
    }

    protected function extractPhoneNumbers(string $text): array
    {
        $phones = [];
        
        $patterns = [
            '/\+?1?\s*\(?([0-9]{3})\)?[\s.-]?([0-9]{3})[\s.-]?([0-9]{4})/',
            '/\b\d{3}[\s.-]\d{3}[\s.-]\d{4}\b/',
            '/\+\d{1,3}\s*\d{4,14}/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $phone) {
                    $phones[] = preg_replace('/[^0-9+]/', '', $phone);
                }
            }
        }
        
        return array_unique($phones);
    }

    protected function extractUrls(string $text): array
    {
        $urls = [];
        
        if (preg_match_all('/https?:\/\/[^\s<>"{}|\\^`\[\]]+/', $text, $matches)) {
            $urls = array_unique($matches[0]);
        }
        
        return $urls;
    }

    protected function detectDocumentType(array $extraction): ?string
    {
        $text = is_array($extraction) ? ($extraction['text'] ?? '') : $extraction;
        $text = strtolower($text);
        
        $typeIndicators = [
            'invoice' => ['invoice', 'bill to', 'remit to', 'due date', 'invoice number', 'subtotal'],
            'receipt' => ['receipt', 'transaction', 'cashier', 'change due', 'thank you for'],
            'contract' => ['agreement', 'contract', 'parties', 'whereas', 'terms and conditions'],
            'purchase_order' => ['purchase order', 'po number', 'ship to', 'vendor', 'quantity'],
            'shipping' => ['tracking', 'shipment', 'carrier', 'delivery', 'package'],
        ];
        
        $scores = [];
        
        foreach ($typeIndicators as $type => $indicators) {
            $score = 0;
            foreach ($indicators as $indicator) {
                if (strpos($text, $indicator) !== false) {
                    $score++;
                }
            }
            $scores[$type] = $score;
        }
        
        arsort($scores);
        $topType = key($scores);
        
        return $scores[$topType] > 0 ? $topType : null;
    }

    protected function analyzeLayout(array $bounds): array
    {
        return [
            'regions' => $this->identifyRegions($bounds),
            'columns' => $this->detectColumns($bounds),
            'tables' => $this->detectTables($bounds),
        ];
    }

    protected function identifyRegions(array $bounds): array
    {
        return [];
    }

    protected function detectColumns(array $bounds): array
    {
        return [];
    }

    protected function detectTables(array $bounds): array
    {
        return [];
    }

    protected function extractPdfMetadata(string $pdfPath): array
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($pdfPath);
            
            $details = $pdf->getDetails();
            
            return [
                'pdf_pages' => $details['Pages'] ?? null,
                'pdf_author' => $details['Author'] ?? null,
                'pdf_creator' => $details['Creator'] ?? null,
                'pdf_title' => $details['Title'] ?? null,
                'pdf_subject' => $details['Subject'] ?? null,
                'pdf_creation_date' => isset($details['CreationDate']) ? date('Y-m-d H:i:s', strtotime($details['CreationDate'])) : null,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function saveToDatabase(array $data, $originalDocument, array $options): ProcessedDocument
    {
        return ProcessedDocument::create([
            'original_filename' => basename($originalDocument),
            'document_type' => $data['document_type'] ?? null,
            'extracted_data' => $data,
            'template_id' => $data['template_id'] ?? null,
            'confidence_score' => $data['confidence'] ?? 0,
            'processing_time' => $data['metadata']['processing_time'] ?? 0,
            'user_id' => $options['user_id'] ?? auth()->id(),
        ]);
    }

    protected function applyPostProcessor(array $result, array $processor): array
    {
        $class = $processor['class'] ?? null;
        
        if ($class && class_exists($class)) {
            $instance = app($class);
            if (method_exists($instance, 'process')) {
                return $instance->process($result, $processor['options'] ?? []);
            }
        }
        
        return $result;
    }

    protected function validateResult(array $result, array $validators): array
    {
        $validation = ['valid' => true, 'errors' => []];
        
        foreach ($validators as $validator) {
            if ($validator['type'] === 'required_fields') {
                foreach ($validator['fields'] as $field) {
                    if (!isset($result['data']['fields'][$field]) || empty($result['data']['fields'][$field]['value'])) {
                        $validation['valid'] = false;
                        $validation['errors'][] = "Required field '{$field}' is missing or empty";
                    }
                }
            }
        }
        
        return $validation;
    }

    /**
     * Advanced line item extraction that handles complex invoices
     * This method uses the proven logic from advanced-invoice-extractor.php
     */
    protected function extractAdvancedLineItems(string $text): array
    {
        $items = [];
        
        // Find the items table section
        $startMarker = 'QUANTITY\s+DESCRIPTION\s+UNIT\s*PRICE\s+TOTAL';
        $endMarker = 'SUBTOTAL|SUB\s*TOTAL';
        
        if (preg_match("/$startMarker(.*?)$endMarker/si", $text, $matches)) {
            $itemsSection = $matches[1];
            
            // Split into lines
            $lines = explode("\n", $itemsSection);
            
            $currentItem = null;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Pattern 1: Line starts with quantity (number)
                if (preg_match('/^\s*(\d+)\s+(.+)/', $line, $matches)) {
                    // Save previous item if exists
                    if ($currentItem && isset($currentItem['total']) && (float) $currentItem['total'] > 0) {
                        $items[] = $currentItem;
                    }

                    // Start new item
                    $currentItem = [
                        'quantity' => intval($matches[1]),
                        'description' => trim($matches[2]),
                        'product_code' => '',
                        'unit_price' => '0',
                        'total' => '0',
                    ];

                    // Check if prices are on the same line
                    if (preg_match('/(\d+\.?\d*)\s+(\d+\.?\d*)$/', $currentItem['description'], $priceMatches)) {
                        $currentItem['unit_price'] = $this->parseNumber($priceMatches[1]);
                        $currentItem['total'] = $this->parseNumber($priceMatches[2]);
                        $currentItem['description'] = trim(str_replace($priceMatches[0], '', $currentItem['description']));
                    }
                }
                // Pattern 2: Product code line (starts with letters)
                elseif ($currentItem && preg_match('/^[A-Z]{3,}/', $line)) {
                    // This is likely a product code
                    if (preg_match('/^([A-Z0-9\-]+)/', $line, $codeMatch)) {
                        $currentItem['product_code'] = $codeMatch[1];
                    }

                    // Check for prices on this line
                    if (preg_match('/(\d+\.?\d*)\s+(\d+\.?\d*)$/', $line, $priceMatches)) {
                        $currentItem['unit_price'] = $this->parseNumber($priceMatches[1]);
                        $currentItem['total'] = $this->parseNumber($priceMatches[2]);
                    }
                }
                // Pattern 3: Just prices (continuation line)
                elseif ($currentItem && preg_match('/^\s*(\d+\.?\d*)\s+(\d+\.?\d*)$/', $line, $priceMatches)) {
                    $currentItem['unit_price'] = $this->parseNumber($priceMatches[1]);
                    $currentItem['total'] = $this->parseNumber($priceMatches[2]);
                }
            }
            
            // Don't forget the last item
            if ($currentItem && isset($currentItem['total']) && (float) $currentItem['total'] > 0) {
                $items[] = $currentItem;
            }
        }
        
        // Alternative method if first method didn't capture enough items
        if (count($items) < 5) {
            $alternativeItems = $this->extractLineItemsWithRegex($text);
            if (count($alternativeItems) > count($items)) {
                $items = $alternativeItems;
            }
        }
        
        return $items;
    }

    /**
     * Alternative line item extraction using regex patterns
     */
    protected function extractLineItemsWithRegex(string $text): array
    {
        $items = [];
        
        // More flexible patterns to catch all variations
        $patterns = [
            // Pattern 1: Quantity at start of line, prices at end
            '/^\s*(\d+)\s+([A-Za-z].+?)\s+(\d+\.\d{2})\s+(\d+\.\d{2})\s*$/m',
            
            // Pattern 2: Multi-line items (description and code on separate lines)
            '/^\s*(\d+)\s+([A-Za-z][^\n]+)\n\s*([A-Z]{3,}[A-Z0-9\-]+)(?:\s+.*?)?\s+(\d+\.\d{2})\s+(\d+\.\d{2})/m',
            
            // Pattern 3: All on one line with product code in parentheses
            '/^\s*(\d+)\s+(.+?)\s*\(([A-Z0-9\-]+)\)\s+(\d+\.\d{2})\s+(\d+\.\d{2})\s*$/m'
        ];
        
        // Extract using each pattern
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $item = [
                        'quantity' => intval($match[1]),
                        'description' => trim($match[2]),
                        'product_code' => '',
                        'unit_price' => '0',
                        'total' => '0',
                    ];

                    if (count($match) == 5) {
                        // Pattern 1 or 3
                        $item['unit_price'] = $this->parseNumber($match[3]);
                        $item['total'] = $this->parseNumber($match[4]);
                    } elseif (count($match) == 6) {
                        // Pattern 2
                        $item['product_code'] = trim($match[3]);
                        $item['unit_price'] = $this->parseNumber($match[4]);
                        $item['total'] = $this->parseNumber($match[5]);
                    }
                    
                    // Extract product code from description if not already found
                    if (empty($item['product_code']) && preg_match('/([A-Z]{3,}[A-Z0-9\-]+)/', $item['description'], $codeMatch)) {
                        $item['product_code'] = $codeMatch[1];
                        $item['description'] = trim(str_replace($codeMatch[1], '', $item['description']));
                    }
                    
                    $items[] = $item;
                }
            }
        }
        
        // Remove duplicates based on description and total
        $uniqueItems = [];
        $seen = [];
        
        foreach ($items as $item) {
            $key = $item['description'] . '|' . $item['total'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueItems[] = $item;
            }
        }
        
        return $uniqueItems;
    }

    /**
     * Extract invoice totals (subtotal, tax, shipping, total due)
     */
    protected function extractInvoiceTotals(string $text): array
    {
        $totals = [];

        $toDecimal = function (string $raw): string {
            return $this->parseNumber($raw);
        };

        // Extract subtotal
        if (preg_match('/SUBTOTAL\s+([\d,]+\.?\d*)/i', $text, $matches)) {
            $dec = $toDecimal($matches[1]);
            $totals['subtotal'] = ['amount' => $dec, 'currency' => 'USD', 'raw' => $matches[1]];
        }

        // Extract tax
        if (preg_match('/(?:SALES\s*)?TAX\s+([\d,]+\.?\d*)/i', $text, $matches)) {
            $dec = $toDecimal($matches[1]);
            $totals['tax'] = ['amount' => $dec, 'currency' => 'USD', 'raw' => $matches[1]];
        }

        // Extract shipping
        if (preg_match('/SHIPPING\s*&?\s*HANDLING\s+([\d,]+\.?\d*)/i', $text, $matches)) {
            $dec = $toDecimal($matches[1]);
            $totals['shipping'] = ['amount' => $dec, 'currency' => 'USD', 'raw' => $matches[1]];
        }

        // Extract total
        if (preg_match('/TOTAL\s*DUE\s+([\d,]+\.?\d*)/i', $text, $matches)) {
            $dec = $toDecimal($matches[1]);
            $totals['total'] = ['amount' => $dec, 'currency' => 'USD', 'raw' => $matches[1]];
        }

        return $totals;
    }
}