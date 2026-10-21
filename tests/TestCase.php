<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // public/build is gitignored, so CI has no Vite manifest.
        $this->withoutVite();
    }
}
