<?php

/**
 * The Krotze HTTP API, as an OpenAPI 3.1 document.
 *
 * Authored as a PHP array rather than YAML or JSON for two reasons: there is no
 * YAML parser in vendor/ (the project runs on the Laravel skeleton alone, by
 * design), and a PHP array can carry comments and share fragments through
 * variables. It is served as JSON from GET /api/openapi.json, and
 * tests/Feature/OpenApiSpecTest.php checks it against the real route table in
 * both directions so the two cannot drift apart.
 *
 * `servers` and `info.version` are filled in by the route that serves this.
 */

/* ----------------------------- fragments ---------------------------------- */

/** Every signed operation carries all four headers together. */
$signed = [['ChatDevice' => [], 'ChatTs' => [], 'ChatNonce' => [], 'ChatSig' => []]];

/** Operations that deliberately take no credential. */
$public = [];

$ref = fn (string $name) => ['$ref' => '#/components/schemas/'.$name];
$errorRef = fn (string $name) => ['$ref' => '#/components/responses/'.$name];

/** A JSON request body. */
$body = fn (array $schema, bool $required = true) => [
    'required' => $required,
    'content' => ['application/json' => ['schema' => $schema]],
];

/** A JSON response. */
$json = fn (string $description, array $schema) => [
    'description' => $description,
    'content' => ['application/json' => ['schema' => $schema]],
];

$object = fn (array $properties, array $required = []) => array_filter([
    'type' => 'object',
    'properties' => $properties,
    'required' => $required ?: null,
]);

$string = fn (?string $description = null, array $extra = []) => array_filter(
    array_merge(['type' => 'string', 'description' => $description], $extra),
    fn ($v) => $v !== null,
);

$bool = fn (?string $description = null) => array_filter(['type' => 'boolean', 'description' => $description]);
$int = fn (?string $description = null) => array_filter(['type' => 'integer', 'description' => $description]);

$nullable = fn (string $type, ?string $description = null) => array_filter([
    'type' => [$type, 'null'], 'description' => $description,
]);

/** Path parameters, declared once and referenced everywhere. */
$param = fn (string $name) => ['$ref' => '#/components/parameters/'.$name];

/** The responses every signed operation can produce. */
$signedErrors = [
    '401' => $errorRef('Unauthorized'),
    '403' => $errorRef('Forbidden'),
];

/* ------------------------------ the document ------------------------------ */

return [
    'openapi' => '3.1.0',

    'info' => [
        'title' => 'Krotze API',
        'summary' => 'Anonymous, private, self-destructing group chat.',
        'description' => <<<'MD'
The API behind [Krotze](https://krotze.com) — the same one the web app uses.
Everything the official client can do, a custom client can do.

## There is no login

There is no password, no e-mail and no bearer token. An identity is a username
you pick plus a **device secret** held by each device that may act as you. A
device gets its secret exactly once, in the response to `POST /api/session`
(or `/api/devices/claim` or `/api/devices/import`), and it never travels again.

Three ways to obtain credentials:

| How | Endpoint | When |
| --- | --- | --- |
| Register a new identity | `POST /api/session` | first run |
| Pair with an identity that already exists | `POST /api/devices/claim` | a second device, using an 8-character code |
| Import an exported identity | `POST /api/devices/import` | moving to a new device |

## Signing a request

Every authenticated call proves it holds the device secret by signing the
request, rather than sending the secret. Four headers:

| Header | Value |
| --- | --- |
| `X-Chat-Device` | the `device_id` from your credentials |
| `X-Chat-Ts` | current Unix time in **seconds** |
| `X-Chat-Nonce` | fresh random hex, 16–64 characters |
| `X-Chat-Sig` | the signature, lower-case hex |

The signature is `HMAC-SHA256(secret, canonical)` where `canonical` is these
five lines joined by `\n`:

```
METHOD
REQUEST_URI
TIMESTAMP
NONCE
SHA256_HEX(body)
```

Worked example — `GET /api/state` with secret `0f1e2d…`, timestamp
`1769000000` and nonce `a3f1…`:

```
GET
/api/state
1769000000
a3f19c04e7b25d8a6f0c1b3e5d7a9f21
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

(`e3b0c4…` is SHA-256 of the empty string — a GET has no body.)

### Three things that trip people up

1. **`REQUEST_URI` includes the `/api` prefix and the query string.** Sign
   `/api/channels/{uuid}/messages?after=42`, not `/channels/{uuid}/messages`.
2. **The secret is used as a literal ASCII string**, not hex-decoded. It looks
   like hex, but feed the 64 characters to HMAC as they are.
3. **Multipart bodies sign as the hash of the empty string.** PHP consumes a
   multipart body before it can be hashed, so both sides apply the same rule.
   Only file uploads are affected.

The server allows five minutes of clock drift and burns each nonce once, so a
recorded request can neither be replayed nor turned into a stolen identity. A
`401` with `reason: "stale"` means your clock is off — take `server_time` from
the body (or the `Date` header), keep the offset, and sign again.

The canonical string names no host, and the API answers every origin (CORS), so
one client can hold a separate identity on several Krotze servers and talk to
all of them — the reference app does exactly that.

## Getting new messages

There is no long-poll or socket. Clients poll:

- `GET /api/state` roughly every 4 seconds — channel list, unread counts,
  pending notifications, and a preview of each channel's newest message.
- `GET /api/channels/{uuid}/messages?after={id}` for the messages themselves.

For notifications while your client is **closed**, subscribe to Web Push — see
the `push` operations and `docs/API.md`.

## Errors

Failures are JSON: `{"error": "…"}`, often with a machine-readable `reason`.
Validation failures follow Laravel's shape: `{"message": "…", "errors": {"field": ["…"]}}`.

The full narrative guide, including a reference client, lives in
[`docs/API.md`](https://github.com/) in the source tree.
MD,
        'version' => '0.0.0', // replaced at serve time with config('app.version')
        'license' => ['name' => 'MIT'],
    ],

    'servers' => [
        ['url' => 'https://krotze.com', 'description' => 'Replaced at serve time with this installation.'],
    ],

    'tags' => [
        ['name' => 'Identity', 'description' => 'Registering devices, pairing, importing and exporting an identity.'],
        ['name' => 'Profile', 'description' => 'Username, registered devices, account deletion.'],
        ['name' => 'State', 'description' => 'The poll endpoint every client lives on.'],
        ['name' => 'Channels', 'description' => 'Creating, configuring and destroying channels.'],
        ['name' => 'Members', 'description' => 'Join requests, approvals, removals, private conversations.'],
        ['name' => 'Messages', 'description' => 'Reading, posting and deleting messages and uploads.'],
        ['name' => 'Push', 'description' => 'Web Push subscriptions, for notifications while the client is closed.'],
        ['name' => 'Uploads', 'description' => 'The per-member capability URLs that serve uploaded files.'],
        ['name' => 'Links', 'description' => 'Deep links a client may need to open or generate.'],
    ],

    /* ------------------------------ components ---------------------------- */

    'components' => [

        'securitySchemes' => [
            'ChatDevice' => [
                'type' => 'apiKey', 'in' => 'header', 'name' => 'X-Chat-Device',
                'description' => 'The `device_id` from your credentials.',
            ],
            'ChatTs' => [
                'type' => 'apiKey', 'in' => 'header', 'name' => 'X-Chat-Ts',
                'description' => 'Unix seconds. More than 5 minutes out and the request is refused.',
            ],
            'ChatNonce' => [
                'type' => 'apiKey', 'in' => 'header', 'name' => 'X-Chat-Nonce',
                'description' => 'Fresh random hex, 16–64 characters. Accepted once each.',
            ],
            'ChatSig' => [
                'type' => 'apiKey', 'in' => 'header', 'name' => 'X-Chat-Sig',
                'description' => 'HMAC-SHA256 over the canonical request, lower-case hex. See the introduction.',
            ],
        ],

        'parameters' => [
            'channelUuid' => [
                'name' => 'uuid', 'in' => 'path', 'required' => true,
                'description' => 'Channel UUID. Changes if the owner rotates the invite — watch for `channel_moved`.',
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ],
            'memberId' => [
                'name' => 'memberId', 'in' => 'path', 'required' => true,
                'description' => 'Membership id, as returned in a channel detail `members[]` entry.',
                'schema' => ['type' => 'integer'],
            ],
            'messageId' => [
                'name' => 'messageId', 'in' => 'path', 'required' => true,
                'schema' => ['type' => 'integer'],
            ],
            'deviceId' => [
                'name' => 'deviceId', 'in' => 'path', 'required' => true,
                'schema' => ['type' => 'integer'],
            ],
            'inviteToken' => [
                'name' => 'token', 'in' => 'path', 'required' => true,
                'description' => 'The channel invite token, from an invite URL or QR code.',
                'schema' => ['type' => 'string'],
            ],
        ],

        'responses' => [
            'Unauthorized' => $json(
                'The signature was missing, wrong, stale or replayed. `reason` says which.',
                $object([
                    'error' => $string(),
                    'reason' => $string('One of the values listed.', ['enum' => [
                        'unsigned', 'stale', 'bad_nonce', 'device_unknown', 'bad_signature', 'replay',
                    ]]),
                    'server_time' => $int('Only with `stale`: the server\'s Unix time, so a client on another origin (which cannot read the `Date` header) can correct its clock.'),
                ]),
            ),
            'Forbidden' => $json(
                'Allowed to ask, not allowed to do. Either this identity is not approved on the server yet, or the action needs rights it does not have.',
                $object([
                    'error' => $string(),
                    'reason' => $string('Absent when the refusal is about channel rights rather than the account.', ['enum' => [
                        'registration_pending', 'registration_denied',
                    ]]),
                ]),
            ),
            'NotFound' => $json('No such channel, member, message or token.', ['$ref' => '#/components/schemas/Error']),
            'ValidationFailed' => $json('The body did not validate.', ['$ref' => '#/components/schemas/ValidationError']),
            'TooManyRequests' => [
                'description' => 'Rate limited. `Retry-After` says for how long.',
                'headers' => ['Retry-After' => ['schema' => ['type' => 'integer'], 'description' => 'Seconds.']],
            ],
        ],

        'schemas' => [

            'Error' => $object(['error' => $string('Human-readable, safe to show to a person.')], ['error']),

            'ValidationError' => $object([
                'message' => $string(),
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'description' => 'Field name to the messages for it.',
                ],
            ]),

            'Ok' => $object(['ok' => $bool()]),

            'Credentials' => $object([
                'device_id' => $string('Send as `X-Chat-Device`.'),
                'secret' => $string('The HMAC key. Returned **once** — store it and never send it again.'),
                'user_id' => $int('Your numeric identity id; appears as `user_id` on your own messages.'),
                'username' => $nullable('string', 'Null until you claim one with `POST /api/profile/username`.'),
                'status' => $string('Whether the server has let this identity in.', [
                    'enum' => ['approved', 'pending', 'denied'],
                ]),
            ], ['device_id', 'secret', 'user_id']),

            'MessagePreview' => $object([
                'id' => $int('Newest message id in the channel.'),
                'user_id' => $nullable('integer', 'Author; compare with your own to ignore your own messages.'),
                'username' => $string(),
                'excerpt' => $nullable('string', 'Message text, truncated, or the file name for an upload. Null for an end-to-end encrypted message — show a lock placeholder.'),
                'encrypted' => $bool('True when the message is end-to-end encrypted and there is nothing to excerpt.'),
            ]),

            'StateChannel' => $object([
                'uuid' => $string('', ['format' => 'uuid']),
                'name' => $string('For a private conversation, the other participant.'),
                'type' => $string('', ['enum' => ['group', 'private']]),
                'status' => $string('Your membership.', ['enum' => ['pending', 'approved']]),
                'username' => $string('Your own username.'),
                'is_owner' => $bool(),
                'pinned' => $bool(),
                'hidden' => $bool(),
                'muted' => $bool('Muted channels raise no notification; the unread count still counts.'),
                'unread' => $int('Messages after your `last_read_message_id` that are not yours.'),
                'last_message' => ['anyOf' => [['$ref' => '#/components/schemas/MessagePreview'], ['type' => 'null']]],
                'retention_days' => $int('Inactivity after which the whole channel deletes itself.'),
                'invite_url' => $nullable('string', 'Owners only.'),
                'pending_count' => $int('Join requests waiting for you, if you own the channel.'),
                'member_count' => $int(),
                'members_hash' => $string('Changes whenever anyone joins, leaves or renames. Reload the roster when it moves.'),
                'last_activity_at' => $nullable('string'),
            ]),

            'Notification' => $object([
                'id' => $int(),
                'type' => $string('', ['enum' => [
                    'join_request', 'join_approved', 'join_denied',
                    'private_invite', 'private_accepted', 'private_declined',
                    'ownership_received', 'member_removed', 'channel_destroyed', 'channel_moved',
                ]]),
                'data' => [
                    'type' => 'object', 'additionalProperties' => true,
                    'description' => 'Depends on `type`: `channel_uuid`, `channel_name`, `member_id`, `username`, `from_username`, `old_uuid`.',
                ],
                'created_at' => $string('', ['format' => 'date-time']),
            ]),

            'StateResponse' => $object([
                'user_id' => $int(),
                'username' => $nullable('string'),
                'account_status' => $string('While not `approved`, everything except this endpoint and the username endpoint is refused.', [
                    'enum' => ['approved', 'pending', 'denied'],
                ]),
                'channels' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StateChannel']],
                'notifications' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Notification']],
                'upload_retention_days' => $nullable('integer', 'How long uploads live. Null means they are kept until their channel goes.'),
                'upload_view_minutes' => $nullable('integer', 'How long an upload stays readable once opened. Null means no limit.'),
                'version' => $string('Server version. If it differs from the build you loaded, a newer client exists.'),
            ]),

            'Member' => $object([
                'id' => $int('Membership id — what member-scoped endpoints take.'),
                'username' => $string(),
                'status' => $string('', ['enum' => ['pending', 'approved']]),
                'is_owner' => $bool(),
                'is_me' => $bool(),
                'joined_at' => $nullable('string', ''),
            ]),

            'ChannelDetail' => $object([
                'uuid' => $string('', ['format' => 'uuid']),
                'name' => $string(),
                'type' => $string('', ['enum' => ['group', 'private']]),
                'is_owner' => $bool(),
                'my_member_id' => $int(),
                'my_status' => $string('', ['enum' => ['pending', 'approved']]),
                'pinned' => $bool(),
                'hidden' => $bool(),
                'muted' => $bool(),
                'retention_days' => $int(),
                'allow_images' => $bool(),
                'allow_videos' => $bool(),
                'allow_audio' => $bool(),
                'allow_zip' => $bool(),
                'restrict_delete' => $bool('When true only the uploader and the owner may delete a file.'),
                'join_mode' => $string('`open` channels admit anyone with the invite link instantly.', ['enum' => ['approval', 'open']]),
                'invite_url' => $nullable('string', 'Owners only.'),
                'embed_url' => $nullable('string', 'Owners of open-join channels only: the `/embed/{token}` page to iframe.'),
                'members' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Member'],
                    'description' => 'Pending members are visible to the owner only.'],
                'members_hash' => $string(),
                'devices' => ['anyOf' => [
                    ['type' => 'array', 'description' => 'Private conversations only: every participant device with a '
                        .'registered E2EE public key — the wrap targets for outgoing encrypted messages.',
                        'items' => $object([
                            'id' => $string('The device\'s public id, used as the key of its wrap in the envelope.'),
                            'user_id' => $int(),
                            'key' => $string('X25519 public key, base64.'),
                        ])],
                    ['type' => 'null'],
                ]],
            ]),

            'Message' => $object([
                'id' => $int(),
                'user_id' => $nullable('integer', 'Null once the author has deleted their account.'),
                'username' => $string('Live username, so a rename updates old messages too.'),
                'body' => $nullable('string', 'Text messages only. Stored encrypted at rest. For an end-to-end '
                    .'encrypted message this is the E2EE envelope (see the description above), not readable text.'),
                'kind' => $string('', ['enum' => ['text', 'image', 'audio', 'video', 'zip']]),
                'encrypted' => $bool('True when `body` is an end-to-end encrypted envelope that only participant devices can open.'),
                'reply_to' => ['anyOf' => [
                    $object([
                        'id' => $int(),
                        'username' => $nullable('string'),
                        'kind' => $nullable('string'),
                        'encrypted' => $bool('True when the quoted message is end-to-end encrypted; `excerpt` is then null.'),
                        'excerpt' => $nullable('string'),
                    ]),
                    ['type' => 'null'],
                ]],
                'file_url' => $nullable('string', 'Capability URL, personal to you. Opening it starts the view window.'),
                'thumb_url' => $nullable('string', 'Preview. Fetching it does **not** start the view window.'),
                'file_name' => $nullable('string', 'Stored encrypted at rest.'),
                'file_mime' => $nullable('string'),
                'file_size' => $nullable('integer'),
                'file_expires_at' => $nullable('string', 'When the upload itself disappears. Null if the server keeps uploads.'),
                'view_expires_at' => $nullable('string', 'When your personal link stops resolving. Null until you first open it.'),
                'created_at' => $string('', ['format' => 'date-time']),
            ]),

            'Device' => $object([
                'id' => $int(),
                'name' => $string('Self-reported, e.g. "Firefox on Android".'),
                'current' => $bool('True for the device making this call.'),
                'last_seen_at' => $nullable('string'),
                'created_at' => $nullable('string'),
            ]),

            'PushPreferences' => $object([
                'notify_messages' => $bool(),
                'notify_chat_requests' => $bool(),
                'notify_join_requests' => $bool(),
                'hide_message_text' => $bool('Send "New message in …" instead of the sender and text.'),
            ]),
        ],
    ],

    /* -------------------------------- paths -------------------------------- */

    'paths' => [

        /* --- identity --- */

        '/api/session' => ['post' => [
            'tags' => ['Identity'], 'security' => $public,
            'summary' => 'Register a new identity and device',
            'description' => "Mints a brand-new anonymous identity and returns the only copy of the device secret.\n\n"
                .'On a server with **open registrations** anybody may call this. When registrations are closed you must pass the '
                .'`invite` token from a `/register/{token}` link, and the admin may additionally require manual approval — in which '
                ."case `status` comes back `pending` and almost everything is refused until an admin approves you.\n\n"
                .'Rate limited to 30 requests per minute.',
            'requestBody' => $body($object([
                'device_name' => $string('Shown in the profile\'s device list. **Required** — its absence marks a pre-2.0 client.'),
                'invite' => $nullable('string', 'Registration invite token. Required while registrations are closed.'),
            ], ['device_name'])),
            'responses' => [
                '201' => $json('Registered.', $ref('Credentials')),
                '403' => $json('Registrations are closed and no valid invite was presented.', $object([
                    'error' => $string(), 'reason' => $string('', ['enum' => ['registration_closed']]),
                ])),
                '426' => $json('`device_name` was missing — the caller is an outdated client.', $ref('Error')),
                '429' => $errorRef('TooManyRequests'),
            ],
        ]],

        '/api/devices/claim' => ['post' => [
            'tags' => ['Identity'], 'security' => $public,
            'summary' => 'Redeem a pairing code',
            'description' => 'Registers this device against an identity that already exists, using the 8-character code from '
                ."`POST /api/profile/devices/pair`. The new device gets its **own** secret; the existing one's is not shared.\n\n"
                .'Codes last five minutes and work once. Rate limited to 10 requests per minute.',
            'requestBody' => $body($object([
                'code' => $string('8 characters, case-insensitive.'),
                'device_name' => $string(),
            ], ['code'])),
            'responses' => [
                '201' => $json('Paired.', $ref('Credentials')),
                '404' => $json('Unknown or expired code.', $ref('Error')),
                '429' => $errorRef('TooManyRequests'),
            ],
        ]],

        '/api/devices/import' => ['post' => [
            'tags' => ['Identity'], 'security' => $public,
            'summary' => 'Import an exported identity',
            'description' => 'Continues as the identity behind an exported token, registering this device under it. The token is '
                ."stored only as a hash, so a database leak cannot reproduce it.\n\nRate limited to 10 requests per minute.",
            'requestBody' => $body($object([
                'token' => $string('The identity token, at least 32 characters.'),
                'device_name' => $string(),
            ], ['token'])),
            'responses' => [
                '201' => $json('Imported.', $ref('Credentials')),
                '404' => $json('The token is unknown or was revoked.', $ref('Error')),
                '429' => $errorRef('TooManyRequests'),
            ],
        ]],

        /* --- state --- */

        '/api/state' => ['get' => [
            'tags' => ['State'], 'security' => $signed,
            'summary' => 'Everything the client needs to render itself',
            'description' => "The endpoint a client lives on: poll it about every 4 seconds.\n\n"
                ."It returns your channels with unread counts, any pending notifications, and a preview of each channel's newest "
                ."message — enough to raise a notification without fetching the messages themselves.\n\n"
                .'Watch `members_hash` per channel to know when to reload a roster, and `version` to notice that a newer client '
                .'exists. This is also the only endpoint an identity awaiting approval may call.',
            'responses' => ['200' => $json('Current state.', $ref('StateResponse'))] + $signedErrors,
        ]],

        '/api/me' => ['delete' => [
            'tags' => ['Profile'], 'security' => $signed,
            'summary' => 'Delete this identity and everything it owns',
            'description' => 'Irreversible. Channels you own and private conversations you are in are destroyed for everyone else '
                .'too; in channels you merely joined, your messages and files are deleted and the rest stays. Your devices, '
                .'memberships and push subscriptions go with you.',
            'responses' => ['200' => $json('Deleted.', $ref('Ok'))] + $signedErrors,
        ]],

        /* --- profile --- */

        '/api/profile' => ['get' => [
            'tags' => ['Profile'], 'security' => $signed,
            'summary' => 'Username and registered devices',
            'responses' => ['200' => $json('Your profile.', $object([
                'username' => $nullable('string'),
                'has_transfer_token' => $bool('Whether an exported identity token is currently valid.'),
                'devices' => ['type' => 'array', 'items' => $ref('Device')],
            ]))] + $signedErrors,
        ]],

        '/api/profile/username' => ['post' => [
            'tags' => ['Profile'], 'security' => $signed,
            'summary' => 'Claim or change your username',
            'description' => 'Globally unique and compared case-insensitively, so nobody can impersonate anybody. Renaming '
                .'updates your old messages too, since the name is resolved live. Most actions require a username first.',
            'requestBody' => $body($object([
                'username' => $string('2–40 characters: letters, numbers, spaces, dots, dashes, underscores. Must not start with punctuation.'),
            ], ['username'])),
            'responses' => [
                '200' => $json('Claimed.', $object(['ok' => $bool(), 'username' => $string()])),
                '422' => $errorRef('ValidationFailed'),
            ] + $signedErrors,
        ]],

        '/api/profile/devices/key' => ['post' => [
            'tags' => ['Profile'], 'security' => $signed,
            'summary' => 'Register this device\'s E2EE public key',
            'description' => "Uploads the device's X25519 public key so other participants of private conversations can "
                .'wrap message keys for it. Generate the pair locally and never send the private half. Re-uploading '
                ."replaces the key: messages wrapped for the old one stay unreadable, which is the correct outcome.\n\n"
                ."**How Krotze's E2EE envelope works** (private 1:1 text messages): the sender encrypts the text once with "
                .'a random key (XSalsa20-Poly1305 secretbox), then wraps that key for every participant device via an '
                .'ephemeral X25519 box. The message `body` is the JSON envelope '
                .'`{v:1, n, ct, eph, keys: {"<device public id>": {n, k}}}` (all values base64) and the message is posted '
                .'with `encrypted: true`. A device without a wrap — added after the message was sent — cannot decrypt it.',
            'requestBody' => $body($object([
                'public_key' => $string('X25519 public key, base64 (44 characters).'),
            ], ['public_key'])),
            'responses' => ['200' => $json('Registered.', $ref('Ok')), '422' => $errorRef('ValidationFailed')] + $signedErrors,
        ]],

        '/api/profile/devices/pair' => ['post' => [
            'tags' => ['Identity'], 'security' => $signed,
            'summary' => 'Start pairing another device',
            'description' => 'Returns a short code for another device to redeem with `POST /api/devices/claim`. Good for one '
                .'device and five minutes.',
            'responses' => [
                '200' => $json('Code issued.', $object([
                    'code' => $string('8 characters from an alphabet with no easily confused letters.'),
                    'url' => $string('Deep link carrying the code, suitable for a QR code.'),
                    'expires_in' => $int('Seconds.'),
                ])),
                '422' => $json('You need a username before you can pair a device.', $ref('Error')),
            ] + $signedErrors,
        ]],

        '/api/profile/devices/{deviceId}' => ['delete' => [
            'tags' => ['Identity'], 'security' => $signed,
            'summary' => 'Revoke another device',
            'description' => 'That device\'s next request fails and it wipes its local identity. Its push subscription goes too. '
                .'You cannot revoke the device you are calling from.',
            'parameters' => [$param('deviceId')],
            'responses' => [
                '200' => $json('Revoked.', $ref('Ok')),
                '404' => $errorRef('NotFound'),
                '422' => $json('That is the device you are using.', $ref('Error')),
            ] + $signedErrors,
        ]],

        '/api/profile/transfer' => [
            'post' => [
                'tags' => ['Identity'], 'security' => $signed,
                'summary' => 'Mint an identity token for export',
                'description' => 'Returned once and stored only as a hash. The official client encrypts it with a passphrase '
                    .'before showing or saving it — see the `KRZ1` format in `docs/API.md`. Minting a new one invalidates the previous.',
                'responses' => [
                    '200' => $json('Token minted.', $object(['token' => $string(), 'username' => $string()])),
                    '422' => $json('You need a username first.', $ref('Error')),
                ] + $signedErrors,
            ],
            'delete' => [
                'tags' => ['Identity'], 'security' => $signed,
                'summary' => 'Revoke the exported identity token',
                'responses' => ['200' => $json('Revoked.', $ref('Ok'))] + $signedErrors,
            ],
        ],

        '/api/profile/swap-token' => ['post' => [
            'tags' => ['Identity'], 'security' => $signed,
            'summary' => 'Roll every credential of this identity',
            'description' => 'Drops all devices, invalidates any exported token, and hands the caller a fresh device so it stays '
                .'signed in. Username, channels and messages are untouched — this only changes who can act as you. Every other '
                .'device is signed out immediately.',
            'requestBody' => $body($object(['device_name' => $string()]), false),
            'responses' => ['201' => $json('New credentials.', $ref('Credentials'))] + $signedErrors,
        ]],

        /* --- channels --- */

        '/api/channels' => ['post' => [
            'tags' => ['Channels'], 'security' => $signed,
            'summary' => 'Create a channel',
            'description' => 'You become its owner and first approved member.',
            'requestBody' => $body($object([
                'name' => $string('Up to 60 characters.'),
                'retention_days' => $int('Inactivity after which everything in it is deleted. 1–365, default 7.'),
                'join_mode' => $string('`approval` (default): the owner approves every join request. `open`: anyone '
                    .'with the invite link joins instantly — this is what makes a channel embeddable, and it starts '
                    .'with every `allow_*` upload flag off.', ['enum' => ['approval', 'open']]),
            ], ['name'])),
            'responses' => [
                '201' => $json('Created.', $object(['uuid' => $string('', ['format' => 'uuid']), 'invite_url' => $string()])),
                '422' => $errorRef('ValidationFailed'),
            ] + $signedErrors,
        ]],

        '/api/channels/{uuid}' => [
            'get' => [
                'tags' => ['Channels'], 'security' => $signed,
                'summary' => 'Channel detail and member roster',
                'parameters' => [$param('channelUuid')],
                'responses' => ['200' => $json('Detail.', $ref('ChannelDetail')), '404' => $errorRef('NotFound')] + $signedErrors,
            ],
            'patch' => [
                'tags' => ['Channels'], 'security' => $signed,
                'summary' => 'Change channel settings (owner)',
                'parameters' => [$param('channelUuid')],
                'requestBody' => $body($object([
                    'name' => $string(),
                    'retention_days' => $int('1–365.'),
                    'join_mode' => $string('Group channels only. Switching to `open` turns all `allow_*` upload flags '
                        .'off unless the same request sets them explicitly.', ['enum' => ['approval', 'open']]),
                    'allow_images' => $bool(),
                    'allow_videos' => $bool(),
                    'allow_audio' => $bool(),
                    'allow_zip' => $bool(),
                    'restrict_delete' => $bool('Only the uploader and the owner may delete a file.'),
                ])),
                'responses' => ['200' => $json('Saved.', $ref('Ok')), '404' => $errorRef('NotFound'), '422' => $errorRef('ValidationFailed')] + $signedErrors,
            ],
            'delete' => [
                'tags' => ['Channels'], 'security' => $signed,
                'summary' => 'Destroy a channel and all of its data',
                'description' => 'Messages, uploads and memberships are removed from database and disk for everyone. Group '
                    .'channels: owner only. Private conversations: either participant.',
                'parameters' => [$param('channelUuid')],
                'responses' => ['200' => $json('Destroyed.', $ref('Ok')), '404' => $errorRef('NotFound')] + $signedErrors,
            ],
        ],

        '/api/channels/{uuid}/pin' => ['post' => [
            'tags' => ['Channels'], 'security' => $signed,
            'summary' => 'Pin or unpin a channel for yourself',
            'parameters' => [$param('channelUuid')],
            'requestBody' => $body($object(['pinned' => $bool()], ['pinned'])),
            'responses' => ['200' => $json('Saved.', $ref('Ok')), '404' => $errorRef('NotFound')] + $signedErrors,
        ]],

        '/api/channels/{uuid}/hide' => ['post' => [
            'tags' => ['Channels'], 'security' => $signed,
            'summary' => 'Hide or unhide a channel for yourself',
            'parameters' => [$param('channelUuid')],
            'requestBody' => $body($object(['hidden' => $bool()], ['hidden'])),
            'responses' => ['200' => $json('Saved.', $ref('Ok')), '404' => $errorRef('NotFound')] + $signedErrors,
        ]],

        '/api/channels/{uuid}/mute' => ['post' => [
            'tags' => ['Channels'], 'security' => $signed,
            'summary' => 'Mute or unmute a channel',
            'description' => 'Stored on the membership rather than the device, so the choice follows you everywhere. Muted '
                .'channels raise no notification and are not pushed; unread counts still accumulate.',
            'parameters' => [$param('channelUuid')],
            'requestBody' => $body($object(['muted' => $bool()], ['muted'])),
            'responses' => ['200' => $json('Saved.', $ref('Ok')), '404' => $errorRef('NotFound')] + $signedErrors,
        ]],

        '/api/channels/{uuid}/read' => ['post' => [
            'tags' => ['Messages'], 'security' => $signed,
            'summary' => 'Mark messages read up to an id',
            'description' => 'Only ever moves forward. Drives the unread counts in `/api/state`.',
            'parameters' => [$param('channelUuid')],
            'requestBody' => $body($object(['message_id' => $int()], ['message_id'])),
            'responses' => ['200' => $json('Saved.', $ref('Ok')), '404' => $errorRef('NotFound')] + $signedErrors,
        ]],

        '/api/channels/{uuid}/leave' => ['post' => [
            'tags' => ['Members'], 'security' => $signed,
            'summary' => 'Leave a channel',
            'description' => 'An owner must transfer ownership or destroy the channel instead.',
            'parameters' => [$param('channelUuid')],
            'requestBody' => $body($object([
                'delete_own_data' => $bool('Also delete every message and file of yours in it.'),
            ]), false),
            'responses' => [
                '200' => $json('Left.', $ref('Ok')),
                '404' => $errorRef('NotFound'),
                '422' => $json('You own this channel.', $ref('Error')),
            ] + $signedErrors,
        ]],

        '/api/channels/{uuid}/transfer' => ['post' => [
            'tags' => ['Channels'], 'security' => $signed,
            'summary' => 'Hand ownership to another member (owner)',
            'description' => 'You lose every owner right immediately and cannot undo it yourself.',
            'parameters' => [$param('channelUuid')],
            'requestBody' => $body($object(['member_id' => $int('An approved member.')], ['member_id'])),
            'responses' => [
                '200' => $json('Transferred.', $ref('Ok')),
                '404' => $errorRef('NotFound'),
                '422' => $json('That member already owns it.', $ref('Error')),
            ] + $signedErrors,
        ]],

        '/api/channels/{uuid}/rotate' => ['post' => [
            'tags' => ['Channels'], 'security' => $signed,
            'summary' => 'Replace a leaked invite (owner)',
            'description' => 'Issues a new invite token **and** a new channel UUID, so both the join link and the channel address '
                ."change and the old ones stop resolving. Members listed in `keep` move with the channel; everyone else is removed.\n\n"
                .'Kept members learn the new UUID from a `channel_moved` notification on their next poll, so offline clients '
                .'migrate when they return. A client must handle that notification or it will lose the channel.',
            'parameters' => [$param('channelUuid')],
            'requestBody' => $body($object([
                'keep' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Membership ids to carry over. Omit to remove everyone but yourself.'],
            ]), false),
            'responses' => [
                '200' => $json('Rotated.', $object([
                    'uuid' => $string('The new channel UUID.', ['format' => 'uuid']),
                    'invite_url' => $string(),
                    'removed' => $int('How many members were dropped.'),
                ])),
                '404' => $errorRef('NotFound'),
                '422' => $json('Private conversations cannot be rotated.', $ref('Error')),
            ] + $signedErrors,
        ]],

        '/api/channels/{uuid}/purge-uploads' => ['post' => [
            'tags' => ['Messages'], 'security' => $signed,
            'summary' => 'Delete every upload in a channel (owner)',
            'parameters' => [$param('channelUuid')],
            'responses' => [
                '200' => $json('Purged.', $object(['ok' => $bool(), 'deleted' => $int()])),
                '404' => $errorRef('NotFound'),
            ] + $signedErrors,
        ]],

        '/api/channels/{uuid}/private' => ['post' => [
            'tags' => ['Members'], 'security' => $signed,
            'summary' => 'Ask a member for a private conversation',
            'description' => 'Creates a private channel the other side must accept. If one already exists between you, that one '
                .'is returned instead with `existing: true`.',
            'parameters' => [$param('channelUuid')],
            'requestBody' => $body($object(['member_id' => $int()], ['member_id'])),
            'responses' => [
                '200' => $json('A conversation already existed.', $object(['uuid' => $string('', ['format' => 'uuid']), 'existing' => $bool()])),
                '201' => $json('Requested; awaiting acceptance.', $object(['uuid' => $string('', ['format' => 'uuid']), 'existing' => $bool()])),
                '404' => $errorRef('NotFound'),
                '422' => $json('You cannot start one with yourself.', $ref('Error')),
            ] + $signedErrors,
        ]],

        '/api/channels/{uuid}/private-response' => ['post' => [
            'tags' => ['Members'], 'security' => $signed,
            'summary' => 'Accept or decline a private conversation',
            'description' => 'Declining destroys the conversation for both sides.',
            'parameters' => [$param('channelUuid')],
            'requestBody' => $body($object(['accept' => $bool()], ['accept'])),
            'responses' => [
                '200' => $json('Answered.', $ref('Ok')),
                '404' => $errorRef('NotFound'),
                '422' => $json('Not a private conversation awaiting your answer.', $ref('Error')),
            ] + $signedErrors,
        ]],

        '/api/channels/{uuid}/members/{memberId}' => ['delete' => [
            'tags' => ['Members'], 'security' => $signed,
            'summary' => 'Remove a member (owner)',
            'parameters' => [$param('channelUuid'), $param('memberId'), [
                'name' => 'delete_files', 'in' => 'query', 'required' => false,
                'description' => 'Also delete every file that member uploaded here.',
                'schema' => ['type' => 'boolean'],
            ]],
            'responses' => [
                '200' => $json('Removed.', $ref('Ok')),
                '404' => $errorRef('NotFound'),
                '422' => $json('You cannot remove yourself.', $ref('Error')),
            ] + $signedErrors,
        ]],

        '/api/channels/{uuid}/requests' => ['post' => [
            'tags' => ['Members'], 'security' => $signed,
            'summary' => 'Decide several join requests at once (owner)',
            'description' => 'Denied requests are deleted, so the person may apply again. Your matching `join_request` '
                .'notifications are marked read for you.',
            'parameters' => [$param('channelUuid')],
            'requestBody' => $body($object([
                'accept' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'deny' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ])),
            'responses' => [
                '200' => $json('Decided.', $object(['ok' => $bool(), 'decided' => $int()])),
                '404' => $errorRef('NotFound'),
            ] + $signedErrors,
        ]],

        '/api/members/{memberId}/decision' => ['post' => [
            'tags' => ['Members'], 'security' => $signed,
            'summary' => 'Approve or deny one join request (owner)',
            'parameters' => [$param('memberId')],
            'requestBody' => $body($object(['approve' => $bool()], ['approve'])),
            'responses' => [
                '200' => $json('Decided.', $ref('Ok')),
                '404' => $errorRef('NotFound'),
                '422' => $json('That request is no longer pending.', $ref('Error')),
            ] + $signedErrors,
        ]],

        /* --- joining --- */

        '/api/join/{token}' => [
            'get' => [
                'tags' => ['Members'], 'security' => $public,
                'summary' => 'Look up an invite',
                'description' => 'Lets a client show what it is about to join before asking for an identity. No auth needed — '
                    .'holding the invite token is the only thing that grants this.',
                'parameters' => [$param('inviteToken')],
                'responses' => [
                    '200' => $json('Invite is valid.', $object([
                        'name' => $string(),
                        'members' => $int('Approved members.'),
                        'join_mode' => $string('`open` means applying admits you instantly.', ['enum' => ['approval', 'open']]),
                    ])),
                    '404' => $errorRef('NotFound'),
                ],
            ],
            'post' => [
                'tags' => ['Members'], 'security' => $signed,
                'summary' => 'Apply to join a channel',
                'description' => 'You join under your username. In an `approval` channel the owner must approve you; in an '
                    .'`open` channel you are admitted instantly (that is what the embeddable widget relies on), throttled '
                    .'per IP against mass joining. Applying twice returns your current status rather than failing.',
                'parameters' => [$param('inviteToken')],
                'responses' => [
                    '200' => $json('You had already applied or joined.', $object([
                        'uuid' => $string('', ['format' => 'uuid']),
                        'status' => $string('', ['enum' => ['pending', 'approved']]),
                    ])),
                    '201' => $json('Joined (open channels) or applied and the owner has been notified.', $object([
                        'uuid' => $string('', ['format' => 'uuid']),
                        'status' => $string('', ['enum' => ['pending', 'approved']]),
                    ])),
                    '404' => $errorRef('NotFound'),
                    '422' => $json('You need a username first.', $ref('Error')),
                    '429' => $json('Open channels only: too many joins from this IP — try again in a minute.', $ref('Error')),
                ] + $signedErrors,
            ],
        ],

        /* --- messages --- */

        '/api/channels/{uuid}/messages' => [
            'get' => [
                'tags' => ['Messages'], 'security' => $signed,
                'summary' => 'Read messages',
                'description' => 'Without `after`, the newest 100 messages in chronological order — the initial load. With '
                    ."`after`, up to 200 messages newer than that id, which is what the poll loop uses.\n\n"
                    .'`deletions` reports files removed in the last two minutes so a client can show who deleted them at the '
                    .'right place in the list.',
                'parameters' => [$param('channelUuid'), [
                    'name' => 'after', 'in' => 'query', 'required' => false,
                    'description' => 'Return only messages newer than this id. Remember to include the query string when signing.',
                    'schema' => ['type' => 'integer'],
                ]],
                'responses' => ['200' => $json('Messages.', $object([
                    'messages' => ['type' => 'array', 'items' => $ref('Message')],
                    'deletions' => ['type' => 'array', 'items' => $object([
                        'id' => $int(), 'by' => $nullable('string', 'Who deleted it.'),
                    ])],
                ])), '404' => $errorRef('NotFound')] + $signedErrors,
            ],
            'post' => [
                'tags' => ['Messages'], 'security' => $signed,
                'summary' => 'Post a message or upload a file',
                'description' => 'Send JSON for text. Send `multipart/form-data` for an upload — and remember that a multipart '
                    ."body signs as the hash of the **empty string**.\n\n"
                    .'Accepted uploads are images, audio, video and zip archives, up to 50 MB, subject to the channel\'s '
                    .'`allow_*` settings. Images are thumbnailed server-side; for video you may attach your own poster frame '
                    .'as `thumb`, which is re-encoded before use.',
                'parameters' => [$param('channelUuid')],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => ['schema' => $object([
                            'body' => $string('Up to 5000 characters — or, with `encrypted: true`, the E2EE envelope (up to 64 KB).'),
                            'encrypted' => $bool('Private conversations only: `body` is an end-to-end encrypted envelope '
                                .'(see `POST /api/profile/devices/key` for the format). Rejected in group channels.'),
                            'reply_to' => $int('Id of a message in this channel to quote. Ignored if it does not exist.'),
                        ], ['body'])],
                        'multipart/form-data' => ['schema' => $object([
                            'file' => $string('The upload, max 50 MB.', ['format' => 'binary']),
                            'thumb' => $string('Optional poster frame for a video, max 2 MB.', ['format' => 'binary']),
                            'reply_to' => $int(),
                        ], ['file'])],
                    ],
                ],
                'responses' => [
                    '201' => $json('Posted.', $object(['message' => $ref('Message')])),
                    '404' => $errorRef('NotFound'),
                    '422' => $json('Unsupported file type, a kind the channel disallows, or a body that did not validate.', $ref('Error')),
                    '429' => $json('Open-join channels only: posting is throttled per member (the owner is exempt) — slow down.', $ref('Error')),
                ] + $signedErrors,
            ],
        ],

        '/api/channels/{uuid}/messages/{messageId}' => ['delete' => [
            'tags' => ['Messages'], 'security' => $signed,
            'summary' => 'Delete a message outright',
            'description' => 'Its author or the channel owner. Removes the row and any file with it.',
            'parameters' => [$param('channelUuid'), $param('messageId')],
            'responses' => ['200' => $json('Deleted.', $ref('Ok')), '404' => $errorRef('NotFound')] + $signedErrors,
        ]],

        '/api/channels/{uuid}/messages/{messageId}/delete-file' => ['post' => [
            'tags' => ['Messages'], 'security' => $signed,
            'summary' => 'Delete an uploaded file',
            'description' => 'Any member may do this unless the channel sets `restrict_delete`. Leaves a short-lived tombstone '
                .'so other clients can show who removed it; the message itself is pruned within the hour.',
            'parameters' => [$param('channelUuid'), $param('messageId')],
            'responses' => [
                '200' => $json('Deleted.', $object(['ok' => $bool(), 'deleted_by' => $string()])),
                '403' => $json('The channel restricts deletion to the uploader and the owner.', $ref('Error')),
                '404' => $errorRef('NotFound'),
                '422' => $json('Not a file message, or already deleted.', $ref('Error')),
            ] + ['401' => $errorRef('Unauthorized')],
        ]],

        /* --- notifications --- */

        '/api/notifications/read' => ['post' => [
            'tags' => ['State'], 'security' => $signed,
            'summary' => 'Mark notifications read',
            'description' => 'Read notifications stop appearing in `/api/state`. Omit `id` to clear all of them.',
            'requestBody' => $body($object(['id' => $int('Omit to mark every unread notification read.')]), false),
            'responses' => ['200' => $json('Marked.', $ref('Ok'))] + $signedErrors,
        ]],

        /* --- push --- */

        '/api/push/key' => ['get' => [
            'tags' => ['Push'], 'security' => $public,
            'summary' => 'The server\'s VAPID public key',
            'description' => 'Pass `public_key` to `pushManager.subscribe()` as `applicationServerKey`. It is handed to every '
                ."subscribing browser and is not a secret, so this needs no credentials.\n\n"
                .'`enabled` is false when the operator has not configured a keypair or has switched push off; in that case fall '
                ."back to raising notifications from your poll loop.\n\n"
                .'`relay` is true when this server will deliver through another Krotze server\'s relay (see `/api/push/subscribe`) '
                .'— that needs no keypair here, only the operator\'s switch.',
            'responses' => ['200' => $json('Push status.', $object([
                'enabled' => $bool(),
                'public_key' => $nullable('string', 'base64url, 65-byte uncompressed P-256 point.'),
                'relay' => $bool('Whether relay subscriptions are delivered.'),
            ]))],
        ]],

        '/api/push/subscribe' => ['post' => [
            'tags' => ['Push'], 'security' => $signed,
            'summary' => 'Register this device for Web Push',
            'description' => 'Bound to the calling device, so revoking the device also stops its notifications, and each device '
                .'keeps its own preferences. Browsers rotate endpoints on their own schedule — call this on every launch; it is '
                ."idempotent, and registering a new endpoint retires this device's previous one.\n\n"
                ."Payloads are encrypted for your device per RFC 8291, so the push service relays text it cannot read.\n\n"
                .'**Two shapes.** A browser subscription is `endpoint` + `keys`. A *relay* subscription is `relay_url` + '
                .'`relay_token`, for a client whose real push subscription lives on another Krotze server (a browser can hold '
                .'only one, bound to the VAPID key of the server it was installed from): ask that server for a token with its '
                .'`POST /api/push/relay`, hand the pair to this one, and this server delivers through it. The relay URL must be '
                .'https on a public address unless the operator allows otherwise.',
            'requestBody' => $body($object([
                'endpoint' => $string('Browser shape: from `PushSubscription.endpoint`.', ['format' => 'uri']),
                'keys' => $object([
                    'p256dh' => $string('base64url, 65 bytes.'),
                    'auth' => $string('base64url, 16 bytes.'),
                ], ['p256dh', 'auth']),
                'relay_url' => $string('Relay shape: the `relay_url` another Krotze server returned from `POST /api/push/relay`.', ['format' => 'uri']),
                'relay_token' => $string('Relay shape: the `relay_token` from the same answer (48 characters).'),
                'notify_messages' => $bool(),
                'notify_chat_requests' => $bool(),
                'notify_join_requests' => $bool(),
                'hide_message_text' => $bool(),
            ])),
            'responses' => [
                '201' => $json('Subscribed.', $object(['ok' => $bool(), 'preferences' => $ref('PushPreferences')])),
                '422' => $json('Neither shape was complete, or the relay URL is not acceptable (`error` says why).', $ref('Error')),
            ] + $signedErrors,
        ]],

        '/api/push/relay' => [
            'post' => [
                'tags' => ['Push'], 'security' => $signed,
                'summary' => 'Let another Krotze server notify this device through this one',
                'description' => 'For a client that also holds an identity on `origin`. Returns the URL and token to register there '
                    ."with its `POST /api/push/subscribe` (relay shape). Asking again for the same origin rotates the token.\n\n"
                    .'Notifications the other server relays arrive through this device\'s normal subscription here, with an extra '
                    .'`origin` field in the payload naming where they came from. Nothing is delivered unless this device has a '
                    .'browser subscription on this server.',
                'requestBody' => $body($object([
                    'origin' => $string('`scheme://host[:port]` of the other server, nothing after it.'),
                ], ['origin'])),
                'responses' => [
                    '201' => $json('Relay ready.', $object([
                        'relay_url' => $string(null, ['format' => 'uri']),
                        'relay_token' => $string('Shown once; only its hash is kept here.'),
                        'origin' => $string('The origin as normalised.'),
                    ])),
                    '422' => $json('`origin` is not a bare origin.', $ref('Error')),
                ] + $signedErrors,
            ],
            'delete' => [
                'tags' => ['Push'], 'security' => $signed,
                'summary' => 'Withdraw a relay',
                'description' => 'The other server\'s next delivery for this device is answered with `gone`, and it drops the subscription.',
                'requestBody' => $body($object(['origin' => $string()], ['origin'])),
                'responses' => [
                    '200' => $json('Withdrawn (or never existed).', $ref('Ok')),
                    '422' => $json('`origin` is not a bare origin.', $ref('Error')),
                ] + $signedErrors,
            ],
        ],

        '/api/push/relay/deliver' => ['post' => [
            'tags' => ['Push'], 'security' => $public,
            'summary' => 'Deliver notifications on behalf of another server',
            'description' => 'Called server-to-server by a Krotze instance holding relay tokens for devices whose push '
                .'subscription lives here. One request carries up to 100 items — a message to a channel fans out into one call '
                ."per home server, not one per member.\n\n"
                .'Each token authorises its own item and is limited to 30 deliveries a minute; an address is limited to 600 items '
                .'and 120 requests a minute. The answer is per token: `ok` (queued), `gone` (the token was withdrawn — drop that '
                .'subscription, as you would on a 410 from a push service), `no_subscription` (the device has no browser '
                .'subscription here right now; keep trying later) or `throttled`. Delivery happens after this response, so the '
                .'outcome at the push service is not reported. The payload reaches the device unchanged, plus an `origin` field '
                .'taken from the relay itself — the caller cannot pose as another server.',
            'requestBody' => $body($object([
                'items' => ['type' => 'array', 'maxItems' => 100, 'items' => $object([
                    'token' => $string('A `relay_token` a client registered with you.'),
                    'title' => $string('At most 120 characters.'),
                    'body' => $string('At most 400 characters.'),
                    'tag' => $string('At most 120 characters; replaces an earlier card with the same tag.'),
                    'uuid' => $nullable('string', 'The channel to open on tap.'),
                ], ['token'])],
            ], ['items'])),
            'responses' => [
                '202' => $json('Accepted; each token answered individually.', $object([
                    'results' => ['type' => 'object', 'additionalProperties' => $string(null, ['enum' => ['ok', 'gone', 'no_subscription', 'throttled']])],
                ])),
                '422' => $errorRef('ValidationFailed'),
                '429' => $errorRef('TooManyRequests'),
            ],
        ]],

        '/api/push/subscription' => [
            'patch' => [
                'tags' => ['Push'], 'security' => $signed,
                'summary' => 'Change what this device is notified about',
                'description' => 'Only the fields you send are changed.',
                'requestBody' => $body($ref('PushPreferences')),
                'responses' => [
                    '200' => $json('Saved.', $object(['ok' => $bool(), 'preferences' => $ref('PushPreferences')])),
                    '404' => $json('This device has no subscription.', $ref('Error')),
                    '422' => $errorRef('ValidationFailed'),
                ] + $signedErrors,
            ],
            'delete' => [
                'tags' => ['Push'], 'security' => $signed,
                'summary' => 'Stop pushing to this device',
                'description' => 'Unsubscribe in the browser as well, or it will simply be re-registered on the next launch.',
                'responses' => ['200' => $json('Unsubscribed.', $ref('Ok'))] + $signedErrors,
            ],
        ],

        '/api/push/test' => ['post' => [
            'tags' => ['Push'], 'security' => $signed,
            'summary' => 'Send a test notification to this device',
            'description' => 'Useful while wiring up a client. Rate limited to 10 requests per minute.',
            'responses' => [
                '200' => $json('Accepted by the push service.', $ref('Ok')),
                '404' => $json('This device has no subscription.', $ref('Error')),
                '422' => $json('Push is switched off on this server.', $ref('Error')),
                '502' => $json('The push service rejected it; the subscription is probably stale.', $ref('Error')),
                '429' => $errorRef('TooManyRequests'),
            ] + $signedErrors,
        ]],

        /* --- uploads --- */

        '/u/{token}/{variant}' => ['get' => [
            'tags' => ['Uploads'], 'security' => $public,
            'summary' => 'Fetch an upload',
            'description' => 'Uploads are never served from a public path. Each member gets their own capability URL, handed to '
                .'them as `file_url` / `thumb_url` on a message, and holding that URL is the whole authorisation — so it carries '
                ."no signature.\n\n"
                ."Two rules make the link ephemeral:\n\n"
                ."- **The upload itself expires** after the operator's retention window (`upload_retention_days` in `/api/state`), "
                ."after which the file, its thumbnail and its message are deleted for everyone.\n"
                .'- **Your personal window starts the first time you fetch the full file**, and runs for `upload_view_minutes`. '
                ."After that your token is destroyed and the URL stops resolving, for you alone.\n\n"
                .'Fetching `/thumb` does **not** start that window — that is what makes it safe to show a preview inline.',
            'parameters' => [
                ['name' => 'token', 'in' => 'path', 'required' => true,
                    'description' => 'The opaque token from `file_url` or `thumb_url`.',
                    'schema' => ['type' => 'string']],
                ['name' => 'variant', 'in' => 'path', 'required' => true,
                    'description' => 'Omit the segment entirely for the full file; `thumb` for the preview.',
                    'schema' => ['type' => 'string', 'enum' => ['thumb']]],
            ],
            'responses' => [
                '200' => ['description' => 'The file.', 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                '404' => ['description' => 'Unknown token, expired upload, or a view window that has closed.'],
            ],
        ]],

        /* --- deep links --- */

        '/join/{token}' => ['get' => [
            'tags' => ['Links'], 'security' => $public,
            'summary' => 'Channel invite link',
            'description' => 'The URL behind an invite QR code. Serves the web app, which reads the token from the path and '
                .'starts the join flow. A custom client can skip the page and use `GET`/`POST /api/join/{token}` directly.',
            'parameters' => [$param('inviteToken')],
            'responses' => ['200' => ['description' => 'The web app shell.', 'content' => ['text/html' => ['schema' => ['type' => 'string']]]]],
        ]],

        '/embed/{token}' => ['get' => [
            'tags' => ['Links'], 'security' => $public,
            'summary' => 'Embeddable chat widget',
            'description' => 'A minimal, text-only chat page meant to be iframed into any website — the one route served '
                .'with `Content-Security-Policy: frame-ancestors *`. It only works for group channels with `join_mode: open`: '
                .'a visitor registers an identity, picks a username and is admitted instantly. Owners get the ready-made '
                .'snippet from “Embed on a website” in the channel menu, using the channel\'s invite token.',
            'parameters' => [$param('inviteToken')],
            'responses' => ['200' => ['description' => 'The widget page (also for unavailable channels, which it explains).',
                'content' => ['text/html' => ['schema' => ['type' => 'string']]]]],
        ]],

        '/register/{token}' => ['get' => [
            'tags' => ['Links'], 'security' => $public,
            'summary' => 'Server registration invite link',
            'description' => 'Handed out by an admin when the server is invite-only. Serves the web app, which keeps the token '
                .'and presents it to `POST /api/session`. A custom client can read the token from the URL and do the same.',
            'parameters' => [[
                'name' => 'token', 'in' => 'path', 'required' => true,
                'description' => 'The registration invite token.',
                'schema' => ['type' => 'string'],
            ]],
            'responses' => ['200' => ['description' => 'The web app shell.', 'content' => ['text/html' => ['schema' => ['type' => 'string']]]]],
        ]],
    ],
];
