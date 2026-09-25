<?php
// Session teardown is shared with the account-deletion flow in settings.php,
// which must invalidate the session the same way.
require_once __DIR__ . "/config.php";

destroy_session();

header("Location: " . frontend_url("login.html"));
exit();
