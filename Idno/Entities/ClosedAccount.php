<?php

namespace Idno\Entities {

    /**
     * The mark of an account that is closed (User::close()) and not yet deleted: `account_uuid`
     * is the account. The marks together are the set of closed accounts
     * (\Idno\Core\AccountRegistry::closed()), which keeps what those accounts own out of
     * every listing and count. A mark is made before its account is closed and removed after
     * the account record is.
     */
    class ClosedAccount extends AccountRecord
    {

    }

}
