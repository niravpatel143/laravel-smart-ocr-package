<?php

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Exceptions\InvalidDocumentException;
use LaravelSmartOCR\Services\TemporaryDocumentManager;
use LaravelSmartOCR\Tests\TestCase;

class TemporaryDocumentManagerTest extends TestCase
{
    private TemporaryDocumentManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new TemporaryDocumentManager(sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        $this->manager->cleanup();
        parent::tearDown();
    }

    public function test_create_returns_writable_file(): void
    {
        $path = $this->manager->create('jpg');

        $this->assertFileExists($path);
        $this->assertStringEndsWith('.jpg', $path);
    }

    public function test_created_file_has_random_name(): void
    {
        $path1 = $this->manager->create('jpg');
        $path2 = $this->manager->create('jpg');

        $this->assertNotSame($path1, $path2);
    }

    public function test_cleanup_deletes_tracked_files(): void
    {
        $path = $this->manager->create('png');
        $this->assertFileExists($path);

        $this->manager->cleanup();

        $this->assertFileDoesNotExist($path);
    }

    public function test_cleanup_is_idempotent(): void
    {
        $this->manager->create('jpg');
        $this->manager->cleanup();
        // Second cleanup should not throw
        $this->manager->cleanup();
        $this->assertTrue(true);
    }

    public function test_track_registers_external_file(): void
    {
        $external = tempnam(sys_get_temp_dir(), 'ocr_ext_');
        $this->manager->track($external);

        $this->assertContains($external, $this->manager->tracked());

        $this->manager->cleanup();
        $this->assertFileDoesNotExist($external);
    }

    public function test_path_traversal_in_extension_is_sanitised(): void
    {
        // ../evil extension should be stripped to safe characters
        $path = $this->manager->create('../../../etc/passwd');
        // File should be created in temp dir, not at a traversal path
        $this->assertStringStartsWith(sys_get_temp_dir(), $path);
        $this->assertFileExists($path);
    }

    public function test_assert_safe_path_rejects_traversal(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->manager->assertSafePath('/tmp/../../../etc/passwd');
    }
}
