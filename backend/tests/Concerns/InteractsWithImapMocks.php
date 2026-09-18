<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Folder as ImapFolder;
use Webklex\PHPIMAP\Message as ImapMessage;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\AttachmentCollection;
use Webklex\PHPIMAP\Support\MessageCollection;

/**
 * Test helpers for faking the webklex/laravel-imap object graph, since a
 * real Message/Attachment can only be constructed by actually talking to an
 * IMAP server. Every helper here builds either a real, trivially-constructible
 * value object (Address, Attribute) or a Mockery double for the handful of
 * methods our services actually call.
 */
trait InteractsWithImapMocks
{
    protected function imapAddress(?string $name, string $email): Address
    {
        return new Address((object) ['personal' => $name ?? '', 'mail' => $email]);
    }

    /**
     * @param  list<Address>  $addresses
     */
    protected function imapAddressAttribute(array $addresses): Attribute
    {
        return new Attribute('address', $addresses);
    }

    protected function imapAttribute(mixed $value): Attribute
    {
        return new Attribute('attribute', $value);
    }

    /**
     * @param  array<string, mixed>  $attributes  Values returned for magic property access (uid, subject, from, to, cc, bcc, date, message_id, in_reply_to)
     * @param  array<string, mixed>  $methodStubs  Return values for explicit method calls (hasTextBody, getTextBody, hasHTMLBody, getHTMLBody, hasAttachments, getAttachments, hasFlag)
     */
    protected function mockImapMessage(array $attributes, array $methodStubs = []): MockInterface
    {
        $message = Mockery::mock(ImapMessage::class);

        // Property access (e.g. $message->uid) goes through the real, inherited
        // __get(), which Mockery does not reliably override — but __get()
        // itself just delegates to get($name), which Mockery *does* override
        // as an explicitly named method. Stubbing get() is what actually
        // controls what $message->anything returns.
        $message->shouldReceive('get')->andReturnUsing(fn (string $name) => $attributes[$name] ?? null);

        $defaults = [
            'hasTextBody' => true,
            'getTextBody' => '',
            'hasHTMLBody' => false,
            'getHTMLBody' => '',
            'hasAttachments' => false,
            'getAttachments' => AttachmentCollection::make(),
            'hasFlag' => false,
        ];

        $methodStubs = array_merge($defaults, $methodStubs);

        if (isset($methodStubs['getAttachments'])) {
            $methodStubs['getAttachments'] = AttachmentCollection::make($methodStubs['getAttachments']->all());
        }

        foreach ($methodStubs as $method => $value) {
            $message->shouldReceive($method)->andReturn($value)->byDefault();
        }

        if (isset($methodStubs['hasFlag'])) {
            // Allow per-flag overrides, e.g. ['hasFlag' => fn (string $flag) => $flag === 'Seen'].
            $message->shouldReceive('hasFlag')->andReturnUsing(
                is_callable($methodStubs['hasFlag']) ? $methodStubs['hasFlag'] : fn () => $methodStubs['hasFlag'],
            );
        }

        $message->shouldReceive('setFlag')->andReturnTrue()->byDefault();
        $message->shouldReceive('unsetFlag')->andReturnTrue()->byDefault();

        return $message;
    }

    /**
     * Wires up ClientManager -> Client -> Folder(imap) -> WhereQuery so that
     * `$client->getFolderByPath()` returns a folder whose `query()` chain
     * answers a full-UID-list search with $allUids and a targeted fetch with
     * $messagesToReturn, and whose `examine()` reports $uidValidity.
     *
     * @param  list<int>  $allUids
     * @param  list<MockInterface>  $messagesToReturn
     */
    protected function mockFolderSync(int $uidValidity, array $allUids, array $messagesToReturn): void
    {
        $whereQuery = Mockery::mock(WhereQuery::class);
        $whereQuery->shouldReceive('whereAll')->andReturnSelf();
        $whereQuery->shouldReceive('whereUidIn')->andReturnSelf();
        $whereQuery->shouldReceive('whereUid')->andReturnSelf();
        $whereQuery->shouldReceive('leaveUnread')->andReturnSelf();
        $whereQuery->shouldReceive('search')->andReturn(new Collection($allUids));
        $whereQuery->shouldReceive('get')->andReturn(MessageCollection::make($messagesToReturn));

        $imapFolder = Mockery::mock(ImapFolder::class);
        $imapFolder->shouldReceive('examine')->andReturn(['uidvalidity' => $uidValidity]);
        $imapFolder->shouldReceive('query')->andReturn($whereQuery);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('getFolderByPath')->andReturn($imapFolder);

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });
    }

    /**
     * Wires up ClientManager -> Client -> Folder so that appending a raw MIME
     * message succeeds and reports the given UID via `EXAMINE`'s `UIDNEXT`
     * (`SmtpMailService`/`DraftService`'s "find the UID we just got
     * assigned" step, §8 item 61 — no longer a post-append search).
     */
    protected function mockSuccessfulImapAppend(int $uid = 501): void
    {
        // Not used by SmtpMailService (never looks up an old copy), but
        // DraftService::update() always looks for the previous draft copy
        // to delete by UID first — an empty result here just means "nothing
        // to delete", which is fine for callers that don't care about that.
        $whereQuery = Mockery::mock(WhereQuery::class);
        $whereQuery->shouldReceive('whereUid')->andReturnSelf();
        $whereQuery->shouldReceive('leaveUnread')->andReturnSelf();
        $whereQuery->shouldReceive('get')->andReturn(MessageCollection::make([]));

        $imapFolder = Mockery::mock(ImapFolder::class);
        $imapFolder->shouldReceive('examine')->andReturn(['uidnext' => $uid]);
        $imapFolder->shouldReceive('appendMessage')->andReturn([]);
        $imapFolder->shouldReceive('query')->andReturn($whereQuery);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('getFolderByPath')->andReturn($imapFolder);

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });
    }

    /**
     * Wires up ClientManager -> Client -> raw LIST response, for
     * FolderSyncService, which reads folder flags directly off the
     * connection rather than through the lossy Folder wrapper (see
     * FolderTypeMapper's docblock for why).
     *
     * @param  array<string, array{delimiter: string, flags: list<string>}>  $rawFolders
     */
    protected function mockRawFolderList(array $rawFolders): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('validatedData')->andReturn($rawFolders);

        $connection = Mockery::mock(ProtocolInterface::class);
        $connection->shouldReceive('folders')->andReturn($response);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('getConnection')->andReturn($connection);

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });
    }
}
