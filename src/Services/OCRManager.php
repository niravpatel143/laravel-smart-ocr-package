<?php

namespace LaravelSmartOCR\Services;

use Illuminate\Support\Manager;
use LaravelSmartOCR\Drivers\TesseractDriver;
use LaravelSmartOCR\Drivers\GoogleVisionDriver;
use LaravelSmartOCR\Drivers\AWSTextractDriver;
use LaravelSmartOCR\Drivers\AzureOCRDriver;
use LaravelSmartOCR\Contracts\OCRDriver;

class OCRManager extends Manager
{
    protected function createTesseractDriver(): OCRDriver
    {
        return new TesseractDriver($this->config->get('smart-ocr.drivers.tesseract', []));
    }

    protected function createGoogleVisionDriver(): OCRDriver
    {
        return new GoogleVisionDriver($this->config->get('smart-ocr.drivers.google_vision', []));
    }

    protected function createAwsTextractDriver(): OCRDriver
    {
        return new AWSTextractDriver($this->config->get('smart-ocr.drivers.aws_textract', []));
    }

    protected function createAzureDriver(): OCRDriver
    {
        return new AzureOCRDriver($this->config->get('smart-ocr.drivers.azure', []));
    }

    public function getDefaultDriver()
    {
        return $this->config->get('smart-ocr.default', 'tesseract');
    }

    public function extract($document, array $options = [])
    {
        return $this->driver()->extract($document, $options);
    }

    public function extractWithTemplate($document, $templateId, array $options = [])
    {
        $rawText = $this->driver()->extract($document, $options);
        $templateManager = app('smart-ocr.templates');
        
        return $templateManager->applyTemplate($rawText, $templateId);
    }

    public function extractTable($document, array $options = [])
    {
        return $this->driver()->extractTable($document, $options);
    }

    public function extractBarcode($document, array $options = [])
    {
        return $this->driver()->extractBarcode($document, $options);
    }

    public function extractQRCode($document, array $options = [])
    {
        return $this->driver()->extractQRCode($document, $options);
    }
}