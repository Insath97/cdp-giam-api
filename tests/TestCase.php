<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.centrix.frontend_url', 'http://localhost:3001');
        Config::set('services.centrix.api_url', 'http://localhost:8002/api/giam/integration');
        Config::set('services.centrix.client_id', 'giam_centrix_client');
        Config::set('services.centrix.client_secret', 'testing_centrix_secret_key');
        Config::set('services.hrms.api_url', 'http://localhost:8001/api/giam/integration');
        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', 'test_hrms_secret');
        Config::set('services.payroll.api_url', 'http://localhost:8003/api/giam/integration');
        Config::set('services.payroll.client_id', 'test_payroll_client');
        Config::set('services.payroll.client_secret', 'test_payroll_secret');
    }
}
