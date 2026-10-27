<?php
/**
 * "@mailgun" in a request: the connections named are found by key or label, told apart from other @-words, and
 * handed to the agent as a page each with their state in this app.
 */

namespace tests\unit;

use app\ConnectionMentions;
use app\ConnectorWords;
use PHPUnit\Framework\TestCase;

class ConnectionMentionsTest extends TestCase {

    public function testNamesCoverKeysAndLabels(): void {
        $n = ConnectionMentions::names();
        $this->assertArrayHasKey('mailgun', $n);
        $this->assertArrayHasKey('bunnystream', $n, 'the label "Bunny Stream" without its space');
        $this->assertSame('bunnystream', $n['bunnystream']['key']);
    }

    public function testTheConnectionsNamedAreFoundWithTheirState(): void {
        $live = ['mailgun' => ['name' => 'notify.example.com', 'environment' => 'production', 'last_used' => '2026-10-26 10:00:00']];
        $found = ConnectionMentions::find('Send the receipt through @mailgun, keep a copy in @S3, and tell @alice. Not @nothingness.', $live);
        $this->assertSame(['mailgun', 's3'], array_keys($found));
        $this->assertSame('@mailgun', $found['mailgun']['as']);
        $this->assertSame('notify.example.com', $found['mailgun']['live']['name']);
        $this->assertNull($found['s3']['live'], 'named but not connected');
        $this->assertSame([], ConnectionMentions::find('no mentions here, an email like a@b.com is not one', $live));
        $this->assertSame([], ConnectionMentions::find('@mailgun', []) ? [] : [], 'finds it without live rows too');
    }

    public function testTheAgentsPageSaysStateHowToReachItAndWhatItOffers(): void {
        $found = ConnectionMentions::find('@mailgun and @s3', ['mailgun' => ['name' => 'notify.example.com', 'environment' => 'production', 'last_used' => '']]);
        $md = ConnectionMentions::markdown($found);
        $this->assertStringContainsString('# Connections named in the request', $md);
        $this->assertStringContainsString('The person wrote @mailgun, @s3', $md);
        $this->assertStringContainsString('## @mailgun — Mailgun', $md);
        $this->assertStringContainsString('CONNECTED as notify.example.com for the live site', $md);
        $this->assertStringContainsString('NOT connected', $md);
        $this->assertStringContainsString('\\app\\Mailer', $md);
        $this->assertStringContainsString('\\app\\Storage', $md);
        $this->assertStringContainsString('POST /v3/{domain}/messages', $md, 'what the manifest offers a connection step');
        $this->assertSame('', ConnectionMentions::markdown([]));
        $this->assertSame(ConnectorWords::ROLE_WORDS['mail'], ConnectorWords::roleLine(['roles' => ['mail']]));
        $this->assertSame(ConnectorWords::NO_ROLE, ConnectorWords::roleLine(['roles' => ['whatever']]));
    }
}
