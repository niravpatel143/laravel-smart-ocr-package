<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default OCR Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default OCR driver that will be used by the
    | package. You may set this to any of the drivers defined below.
    |
    | Supported: "claude", "openai", "pdf", "tesseract"
    |
    |  claude    — Anthropic Claude Vision API (images + scanned docs, no binary needed)
    |  openai    — OpenAI GPT-4o Vision API   (images + scanned docs, no binary needed)
    |  pdf       — smalot/pdfparser           (digital PDFs only, free, offline)
    |  tesseract — Local Tesseract binary     (requires separate OS install)
    |
    */
    'default' => env('SMART_OCR_DRIVER', 'tesseract'),

    /*
    |--------------------------------------------------------------------------
    | OCR Drivers
    |--------------------------------------------------------------------------
    */
    'drivers' => [

        'google' => [
            'api_key'     => env('SMART_OCR_GOOGLE_API_KEY', ''),
            'credentials' => env('SMART_OCR_GOOGLE_CREDENTIALS', ''),
            'project_id'  => env('SMART_OCR_GOOGLE_PROJECT_ID', ''),
            'location'    => env('SMART_OCR_GOOGLE_LOCATION', 'us'),
            'timeout'     => env('SMART_OCR_GOOGLE_TIMEOUT', 60),
            'ssl_verify'  => true,
        ],

        'aws' => [
            'key'        => env('AWS_ACCESS_KEY_ID', ''),
            'secret'     => env('AWS_SECRET_ACCESS_KEY', ''),
            'region'     => env('SMART_OCR_AWS_TEXTRACT_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'timeout'    => env('SMART_OCR_AWS_TIMEOUT', 120),
            'bucket'     => env('SMART_OCR_AWS_BUCKET', ''),
            'textract'   => [
                'region' => env('SMART_OCR_AWS_TEXTRACT_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            ],
            's3' => [
                'bucket' => env('SMART_OCR_AWS_BUCKET', ''),
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            ],
        ],

        'azure' => [
            'endpoint'    => env('SMART_OCR_AZURE_ENDPOINT', ''),
            'key'         => env('SMART_OCR_AZURE_KEY', ''),
            'api_version' => env('SMART_OCR_AZURE_API_VERSION', '2023-02-01-preview'),
            'timeout'     => env('SMART_OCR_AZURE_TIMEOUT', 60),
            'ssl_verify'  => true,
        ],

        'claude' => [
            'api_key'    => env('ANTHROPIC_API_KEY'),
            'model'      => env('SMART_OCR_CLAUDE_MODEL', 'claude-opus-4-7'),
            'max_tokens' => 4096,
            'timeout'    => 60,
            'ssl_verify' => env('SMART_OCR_SSL_VERIFY', true),
        ],

        'openai' => [
            'api_key'    => env('OPENAI_API_KEY'),
            'model'      => env('SMART_OCR_OPENAI_MODEL', 'gpt-4o'),
            'max_tokens' => 4096,
            'timeout'    => 60,
            'ssl_verify' => env('SMART_OCR_SSL_VERIFY', true),
        ],

        'pdf' => [
            'max_pages' => env('SMART_OCR_PDF_MAX_PAGES', 100),
        ],

        'tesseract' => [
            'binary'   => env('TESSERACT_BINARY', 'C:\Program Files\Tesseract-OCR\tesseract.exe'),
            'language' => env('TESSERACT_LANGUAGE', 'eng'),
            'timeout'  => env('TESSERACT_TIMEOUT', 60),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | AI Cleanup Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the AI cleanup service that processes and structures the
    | extracted text data. You can use different providers for this service.
    |
    */
    'ai_cleanup' => [
        'enabled' => env('SMART_OCR_AI_CLEANUP', false),
        'default_provider' => env('SMART_OCR_AI_PROVIDER', 'openai'),
        
        'providers' => [
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
                'max_tokens' => 2000,
            ],
            
            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('ANTHROPIC_MODEL', 'claude-3-haiku'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Template Storage
    |--------------------------------------------------------------------------
    |
    | Configure where document templates should be stored and how they should
    | be managed. Templates can be stored in database or files.
    |
    */
    'templates' => [
        'storage' => 'database', // 'database' or 'file'
        'path' => storage_path('app/ocr-templates'),
        'cache_enabled' => true,
        'cache_ttl' => 3600, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Options
    |--------------------------------------------------------------------------
    |
    | Configure default processing options for OCR operations.
    |
    */
    'processing' => [
        'image_preprocessing' => true,
        'auto_rotate' => true,
        'enhance_quality' => true,
        'remove_noise' => true,
        'max_file_size' => 10 * 1024 * 1024, // 10MB
        'allowed_formats' => ['jpg', 'jpeg', 'png', 'pdf', 'tiff', 'bmp'],
        'pdf_dpi' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue settings for processing documents asynchronously.
    |
    */
    'queue' => [
        'enabled' => env('SMART_OCR_QUEUE_ENABLED', false),
        'connection' => env('SMART_OCR_QUEUE_CONNECTION', 'default'),
        'queue' => env('SMART_OCR_QUEUE_NAME', 'ocr-processing'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure where processed documents and temporary files should be stored.
    |
    */
    'storage' => [
        'disk' => env('SMART_OCR_STORAGE_DISK', 'local'),
        'temp_path' => storage_path('app/temp/ocr'),
        'processed_path' => storage_path('app/processed/ocr'),
        'cleanup_after' => 24, // hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Configure security settings for the OCR package.
    |
    */
    'security' => [
        'encrypt_stored_data' => env('SMART_OCR_ENCRYPT_DATA', false),
        'scan_for_malware' => env('SMART_OCR_SCAN_MALWARE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Input Validation
    |--------------------------------------------------------------------------
    |
    | Central validation applied to every document before OCR begins.
    | Extension alone cannot bypass MIME validation.
    |
    */
    'validation' => [
        'max_file_size' => env('SMART_OCR_MAX_FILE_SIZE', 10 * 1024 * 1024), // 10 MB
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf', 'tiff', 'bmp'],
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/tiff',
            'image/bmp',
            'application/pdf',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote URL Policy
    |--------------------------------------------------------------------------
    |
    | Remote URL fetching is DISABLED by default to prevent SSRF attacks.
    | Enable only when you explicitly need to fetch documents from the internet.
    | Private IPs, loopback, and cloud-metadata addresses are always blocked.
    |
    */
    'remote_urls' => [
        'allow_remote_urls' => env('SMART_OCR_ALLOW_REMOTE_URLS', false),
        'connect_timeout' => 5,   // seconds
        'total_timeout' => 30,    // seconds
        'max_download_size' => env('SMART_OCR_MAX_DOWNLOAD_SIZE', 10 * 1024 * 1024), // 10 MB
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF Processing
    |--------------------------------------------------------------------------
    */
    'pdf' => [
        'max_pages' => env('SMART_OCR_PDF_MAX_PAGES', 100),
        'dpi' => env('SMART_OCR_PDF_DPI', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Human Review
    |--------------------------------------------------------------------------
    */
    'review' => [
        'confidence_threshold' => env('SMART_OCR_REVIEW_THRESHOLD', 0.80),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy / AI Controls
    |--------------------------------------------------------------------------
    */
    'privacy' => [
        'allow_external_ai' => env('SMART_OCR_ALLOW_EXTERNAL_AI', false),
        'redact_before_ai' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflows
    |--------------------------------------------------------------------------
    |
    | Define custom workflows for different document types.
    |
    */
    'workflows' => [
        'invoice' => [
            'options' => [
                'use_ai_cleanup' => true,
                'auto_detect_template' => true,
                'extract_tables' => true,
            ],
            'post_processors' => [
                ['class' => 'App\OCR\Processors\InvoiceProcessor'],
            ],
            'validators' => [
                ['type' => 'required_fields', 'fields' => ['invoice_number', 'total']],
            ],
        ],
        
        'receipt' => [
            'options' => [
                'use_ai_cleanup' => true,
                'extract_line_items' => true,
            ],
            'post_processors' => [
                ['class' => 'App\OCR\Processors\ReceiptProcessor'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for API endpoints if exposed.
    |
    */
    'rate_limiting' => [
        'enabled' => true,
        'max_requests' => 60,
        'per_minutes' => 1,
    ],
];