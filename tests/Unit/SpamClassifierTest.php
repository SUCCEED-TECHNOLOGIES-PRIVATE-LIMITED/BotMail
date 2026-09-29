<?php

namespace Tests\Unit;

use App\Support\SpamClassifier;
use PHPUnit\Framework\TestCase;

class SpamClassifierTest extends TestCase
{
    public function test_spam_flag_and_dmarc_failure_are_spam(): void
    {
        $this->assertTrue(SpamClassifier::fromHeaders([
            'x-spam-flag' => 'YES',
        ]));

        $this->assertTrue(SpamClassifier::fromHeaders([
            'authentication-results' => 'mx.example.com; dmarc=fail (p=reject)',
        ]));
    }

    public function test_clean_headers_are_not_spam(): void
    {
        $this->assertFalse(SpamClassifier::fromHeaders([
            'authentication-results' => 'mx.example.com; spf=pass; dkim=pass; dmarc=pass',
            'x-spam-flag' => 'NO',
            'x-spam-score' => '1.2',
        ]));
    }
}
