<?php
// github webhook

define('WebhookMustExists', TRUE);

// config
$configFileName = 'webhook-config.inc.php';
if (file_exists($configFileName)) {
    require_once($configFileName);
}
else {
    http_response_code(500);
    error_log('No webhook config');
    die();
}

function is_cli()
{
    if ( defined('STDIN') )
    {
        return true;
    }

    if ( php_sapi_name() === 'cli' )
    {
        return true;
    }

    if ( array_key_exists('SHELL', $_ENV) ) {
        return true;
    }

    if ( empty($_SERVER['REMOTE_ADDR']) and !isset($_SERVER['HTTP_USER_AGENT']) and count($_SERVER['argv']) > 0)
    {
        return true;
    }

    if ( !array_key_exists('REQUEST_METHOD', $_SERVER) )
    {
        return true;
    }

    return false;
}
$isCli = is_cli();
$msg = '';

$githubEvent = $_SERVER['HTTP_X_GITHUB_EVENT'];
if ($githubEvent == 'push' || $githubEvent == 'ping') {
    try {
        $inputJSON = file_get_contents('php://input');
        $githubSign = $_SERVER['HTTP_X_HUB_SIGNATURE_256'];
        $sig_check = 'sha256=' . hash_hmac('sha256', $inputJSON, $secret);
        if (hash_equals($sig_check, $githubSign)) {
            $payload = json_decode($inputJSON, TRUE);

            if ($githubEvent == 'ping') {
                $key = $payload['repository']['full_name'] .'/'. $pingBranch;
            } else {
                $parts = explode('/', $payload['ref']);
                $branchName = $parts[count($parts) - 1];
                $key = $payload['repository']['full_name'] .'/'. $branchName;
            }

            if (isset($branchCfg[$key])) {
                $cfg = $branchCfg[$key];
                $githubDeliveryId = $_SERVER['HTTP_X_GITHUB_DELIVERY'];
                $msg .= 'delivery: '.$githubDeliveryId."\n";

                ob_start();
                echo "OK";
                header('Connection: close');
                header('Content-Length: '.ob_get_length());
                ob_end_flush();
                ob_flush();
                flush();

                $cmds = [
                    'export HOME='.$homePath,
                    'source ~/.bash_profile',
                    'cd '.$cfg['root'],
                ];
                $cmds = array_merge($cmds, $cfg['cmds'] ? $cfg['cmds'] : []);
                $cmd = join('; ', $cmds);
                // $result = $cmd."\n";
                $result .= shell_exec( $cmd );
                $msg .= "(".$cfg['name'].")\n".$result;
            } else {
                error_log("Wrong repository: ".$key);
                http_response_code(400);
                echo "Wrong repository";
            }
        } else {
            error_log('Invalid signature: '.$sig_check);
            http_response_code(400);
            echo "Invalid";
        }
    } catch (\Throwable $err) {
        error_log(print_r($err, true));
        http_response_code(400);
        echo "Invalid";
    }
} else {
    error_log('Invalid event: '.$githubEvent);
}

if ($msg != '') {
    $logfile = fopen($logFileName, "a") or die("Unable to open file!");
    fwrite($logfile, "\n". $msg. "\n\n");
    fclose($logfile);
}
?>
