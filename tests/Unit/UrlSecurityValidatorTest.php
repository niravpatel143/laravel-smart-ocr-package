<?php

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Exceptions\InvalidDocumentException;
use LaravelSmartOCR\Services\UrlSecurityValidator;
use LaravelSmartOCR\Tests\TestCase;

class UrlSecurityValidatorTest extends TestCase
{
    private UrlSecurityValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new UrlSecurityValidator();
    }

    public function test_localhost_is_blocked(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->validator->validate('http://localhost/doc.pdf');
    }

    public function test_loopback_ip_is_blocked(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->validator->validate('http://127.0.0.1/doc.pdf');
    }

    public function test_private_ipv4_10_x_is_blocked(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->validator->validate('http://10.0.0.1/doc.pdf');
    }

    public function test_private_ipv4_192_168_is_blocked(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->validator->validate('http://192.168.1.1/doc.pdf');
    }

    public function test_private_ipv4_172_16_is_blocked(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->validator->validate('http://172.16.0.1/doc.pdf');
    }

    public function test_link_local_metadata_ip_is_blocked(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->validator->validate('http://169.254.169.254/latest/meta-data/');
    }

    public function test_ftp_scheme_is_rejected(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->validator->validate('ftp://example.com/doc.pdf');
    }

    public function test_file_scheme_is_rejected(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->validator->validate('file:///etc/passwd');
    }

    public function test_non_url_is_rejected(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->validator->validate('not-a-url');
    }
}
