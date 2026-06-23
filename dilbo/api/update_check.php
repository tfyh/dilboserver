<?php
// the simplest way to check for news is to get the last writing timestamp.
$last_write_access = file_get_contents("../../var/Run/lastWriteAccess/any");
echo !$last_write_access ? 0.0 : $last_write_access;