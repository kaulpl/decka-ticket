<?php
// Only mounted in the local test site; never included in the installable ZIP.
add_filter('pre_wp_mail',function(){return true;});
