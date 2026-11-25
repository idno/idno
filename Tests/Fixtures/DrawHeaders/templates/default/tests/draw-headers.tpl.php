<?php

// Test-environment only (Tests\Fixtures\TestEventHooks, with IDNO_TEST_DRAW_HEADERS=1): what a plugin's extension of `shell/headers` may
// do, which a page's draw must carry to the response as a legacy page's did. The request's `draw_headers` says which:
//   (none)   replace the type's own headers, send a header twice, add to `Vary` and `Link`;
//   remove   remove one of the type's own headers (`Access-Control-Allow-Origin`);
//   append   add a second value to one of the type's own headers, beside it (`Access-Control-Allow-Origin`);
//   status   set the status with http_response_code();
//   location send a `Location` header.
switch ($_GET['draw_headers'] ?? '') {
    case 'remove':
        header_remove('Access-Control-Allow-Origin');
        break;
    case 'append':
        header('Access-Control-Allow-Origin: https://fixture.example', false);
        break;
    case 'status':
        http_response_code(203);
        break;
    case 'location':
        header('Location: https://fixture.example/moved');
        break;
    default:
        header('Access-Control-Allow-Origin: https://fixture.example');
        header('Content-Type: text/html; charset=utf-8');
        header('X-Fixture-Header: one');
        header('X-Fixture-Header: two', false);
        header('Vary: Cookie', false);
        header('Link: <https://fixture.example/>; rel="fixture"', false);
}
