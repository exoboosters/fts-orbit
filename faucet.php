<html>
<head>
<title>Talleo webwallet</title>
<link rel="shortcut icon" href="images/logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.7.1/css/bulma.min.css">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="header">
        <div class="logo"><img src="/images/logo.png"></div>
        <div class="pagetitle">Talleo Web Wallet</div>
</div>

<div class="page">
<?php
require("config.php");
require("lib/daemon.php");
require("lib/database.php");
require("lib/validate.php");
require("lib/users.php");

try {
  open_database();
} catch (Exception $e) {
  echo '<span class="error">Caught exception while opening database: ', $e->getMessage(), "</span></div></body></html>";
  exit();
}
try {
  check_database();
} catch (Exception $e) {
  echo '<span class="error">Caught exception while reading database: ', $e->getMessage(), "</span></div></body></html>";
  exit();
}
// Check if user has logged in or not?
require("lib/login.php");
//
$address = "";
if (logged_in()) {
  $spendKey = $_COOKIE['spendKey'];
  if (!validate_spendkey($spendKey)) {
    echo "<span class='error'>Invalid spend key!</span></div></body></html>";
    exit();
  }
  $address = get_address($spendKey);
  $params = Array();
  $params['address'] = $address;
  $getBalance = walletrpc_post("getBalance", $params);
  $availableBalance = $getBalance->availableBalance;
  $lockedBalance = $getBalance->lockedAmount;
  require("lib/menu.php");
  echo "<div id='wallet'>Address:&nbsp;", $address, "</div><br>";
  echo "<div id='qr'><img src='qr.php'></div>";
  echo "<div id='content'>";
  if (!(isset($faucetWallet) && validate_address($faucetWallet))) {
    echo "<span class='error'>Faucet disabled.</span></div></body></html>";
    exit();
  }
  echo "Faucet address: " . $faucetWallet . "<br/>";
  $params = Array();
  $params['address'] = $faucetWallet;
  $faucetBalance = walletrpc_post("getBalance", $params);
  $faucetAvailableBalance = $faucetBalance->availableBalance;
  echo "Faucet balance: " . number_format($faucetAvailableBalance/100, 2) . " TLO<br/>";
  if ($faucetAvailableBalance > 0) {
    if (isset($_GET['claim']) && $_GET['claim'] == "yes") {
      $amount = rand(100, 500);
      $feeAmount = 0;
      $now = time();
      $lastWallet = faucet_check_wallet($address);
      $lastIP = faucet_check_ip($_SERVER['REMOTE_ADDR']);
      if ($lastWallet + 86400 > $now || $lastIP + 86400 > $now) {
        echo "<span class='error'>You can only claim once per 24 hours.</span>";
      } else if ($address == $faucetWallet) {
        echo "<span class='error'>You can&apos;t use faucet wallet to claim coins.</span>";
      } else {
        $maxAmount = $faucetAvailableBalance - 1;
        $getFeeAddress = daemonrpc_get("/feeaddress");
        if (array_key_exists('fee_address', $getFeeAddress)) {
          $feeAddress = $getFeeAddress->fee_address;
          if (validate_address($feeAddress)) {
            $feeAmount = min(1, max(floatval($maxAmount) / 40001, 100));
            $maxAmount -= $feeAmount;
          }
        }
        if ($maxAmount < 1) {
          echo "<span class='error'>Faucet does not have enough available balance to send transactions.</span></div></body></html>";
          exit();
        }
        if ($amount > $maxAmount) {
          $amount = $maxAmount;
        }
        // Re-calculate node fee using actual amount
        if ($feeAmount > 0) {
          $feeAmount = min(1, max(floatval($amount) / 40000, 100));
        }
        $params = Array();
        $sourceAddresses = Array();
        $sourceAddresses[] = $faucetWallet;
        $params['addresses'] = $sourceAddresses;
        $params['changeAddress'] = $faucetWallet;
        //
        $transfers = Array();
        $transfers[] = Array("address" => $address, "amount" => intval($amount));
        // Add transfer for node fee only if fee address is valid.
        if ($feeAmount > 0) {
          $transfers[] = Array("address" => $feeAddress, "amount" => intval($feeAmount));
        }
        $params['transfers'] = $transfers;
        $params['fee'] = 1;
        $params['anonymity'] = 0;
        $result = walletrpc_post("sendTransaction", (Object) $params);
        if ($result == NULL) {
          echo "<span class='error'>Internal error, contact webwallet admin!</span></div></body></html>";
          exit();
        }
        if (array_key_exists('error', $result)) {
          if (array_key_exists('message', $result->error)) {
            if ($result->error->message == 'Wrong amount') {
              echo "<span class='error'>Sending failed because there was not enough unlocked balance, available balance ", number_format($faucetAvailableBalance / 100, 2), " TLO!</span></div></body></html>";
              exit();
            } else if ($result->error->message == 'Transaction size is too big') {
              echo "<span class='error'>Sending failed because faucet wallet doesn&apos;t have enough large inputs.</span></div></body></html>";
              exit();
            } else if ($result->error->message == 'Sum overflow') {
              echo "<span class='error'>Sending failed because the transfer amount is too large.</span></div></body></html>";
              exit();
            } else {
              echo "<span class='error'>Sending failed because of error '", $result->error->message, "'!</span></div></body></html>";
              exit();
            }
          }
        }
        if (array_key_exists('transactionHash', $result)) {
          echo "Faucet transaction sent with hash ", $result->transactionHash, "<br>";
          echo "<a href='faucet.php'>Return to webwallet</a><br>";
          faucet_update_wallet($address);
          faucet_update_ip($_SERVER['REMOTE_ADDR']);
        }
      }
    } else {
      echo "<a class='button' href='faucet.php?claim=yes'>Claim</a>";
    }
  } else {
    echo "<span class='error'>Faucet is empty!</a>";
  }
  echo "</div>";
}
?>
</div>
</body>
</html>
