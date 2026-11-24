<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\ApiSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class ApiSignatureVerifierTest extends TestCase
{
    private function sign(string $uri, string $key): string
    {
        return base64_encode(hash_hmac('sha256', $uri, $key, true));
    }

    public function testTheSignatureOfTheAddressWithTheKeyVerifies(): void
    {
        $this->assertTrue((new ApiSignatureVerifier())->verify('/currentUser?x=1', 'k3y', $this->sign('/currentUser?x=1', 'k3y')));
    }

    public function testTheHeaderIsTrimmed(): void
    {
        $this->assertTrue((new ApiSignatureVerifier())->verify('/a', 'k3y', "  " . $this->sign('/a', 'k3y') . " \r\n"));
    }

    public function testAnotherAddressAnotherKeyOrNoSignatureDoNotVerify(): void
    {
        $verifier = new ApiSignatureVerifier();

        $this->assertFalse($verifier->verify('/b', 'k3y', $this->sign('/a', 'k3y')), 'another address');
        $this->assertFalse($verifier->verify('/a?x=1', 'k3y', $this->sign('/a', 'k3y')), 'the query is covered');
        $this->assertFalse($verifier->verify('/a', 'other', $this->sign('/a', 'k3y')), 'another key');
        $this->assertFalse($verifier->verify('/a', 'k3y', ''), 'no signature');
        $this->assertFalse($verifier->verify('/a', 'k3y', 'not base64 at all'));
    }

    public function testAMemberWithNoKeyCannotBeImpersonated(): void
    {
        $this->assertFalse((new ApiSignatureVerifier())->verify('/a', '', $this->sign('/a', '')), 'an HMAC with an empty key is one anybody can make');
    }
}
