<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Seeders\TestSeeder;
use JohnWink\FilamentLeadPipeline\Tests\TestCase;

pest()->extends(
    TestCase::class,
    RefreshDatabase::class,
)->beforeEach(function (): void {
    $this->seed(TestSeeder::class);
    Mail::fake();
})->in('Feature');
