<?php

require_once 'vendor/autoload.php';


// https://docs.ccxt.com/en/latest/manual.html#unified-api
$ascendex = new \ccxt\ascendex([
	'apiKey' => "here-is-api-key",
	'secret' => "here-is-api-secret",
]);

function cancelOrders($pair) 
{
	global $ascendex;
	
	$orders = $ascendex->fetchOpenOrders($pair);
	
	if ($orders and is_array($orders) and count($orders)) {
		foreach ($orders as $order) {
			$ascendex->cancelOrder($order['id'], $pair);
			file_put_contents('/root/google.sheet.api/log', date('Y-m-d H:i'). ' Cancel order '. $pair."\n", FILE_APPEND);
		}
	}
}

function sellAll($pair) 
{
	global $ascendex;
	
	list($asset,) = explode('/', $pair);
	
	$balances = $ascendex->fetchBalance();
	if (isset($balances['free'][$asset])) {
//		$ascendex->createOrder($pair, 'market', 'sell', $balances['free'][$asset]);
		$ascendex->createMarketSellOrder($pair, $balances['free'][$asset]);
		file_put_contents('/root/google.sheet.api/log', date('Y-m-d H:i'). ' Sell by market price ALL ('. $balances['free'][$asset] .') '. $asset."\n", FILE_APPEND);
	}
}

$googleAccountKeyFilePath = __DIR__ . '/google.json';
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $googleAccountKeyFilePath);

$client = new Google\Client();
$client->useApplicationDefaultCredentials();

$client->addScope('https://www.googleapis.com/auth/spreadsheets');

$service = new Google_Service_Sheets($client);

$spreadsheetId = 'here-is-your sheet-id';
$range = 'Sheet1!A3:D10';
$response = $service->spreadsheets_values->get($spreadsheetId, $range);
$values = $response->getValues();

$no = 0;

if ($values and count($values)) {
	foreach ($values as $line) {
		//[0] => BCH
		//[1] => 2022/05/21 23:09:48
		//[2] => 192.14
		//[3] => 0.027

		// или, если пусто
		//[0] =>
		//[1] =>
		//[2] => 0.00001
		//[3] => 0.0001

		if ($line[0]) {
			$trades = $ascendex->fetch_trades($line[0]. '/USDT');
			$rate = $trades[0]['info']['p'];
			
			$profitPercent = ($rate / str_replace(',', '', $line[2]) - 1) * 100;
			$profitUsdt = $rate * str_replace(',', '', $line[3]) * 0.999 - str_replace(',', '', $line[2]) * str_replace(',', '', $line[3]) * 1.001;
			
			file_put_contents(
				'/root/google.sheet.api/log',
				date('Y-m-d H:i'). ': '. $line[0] .'/USDT '.
				sprintf('%.2f', $profitPercent) .'%, '.
				sprintf('%.2f', $profitUsdt) .'$, '.
				'rate = '. $rate .
				"\n", FILE_APPEND);

			// проверю, если потери больше 10 USDT или 2%, продаём нах
			$outPercent = -2;
			$outFix = -13;
			if (($profitPercent < $outPercent) or
				($profitUsdt < $outFix)
			) {
				file_put_contents('/root/google.sheet.api/log', date('Y-m-d H:i'). ' VERY BIG LOSS!!!!'."\n", FILE_APPEND);
				//cancelOrders($line[0]. '/USDT');
				//sellAll($line[0]. '/USDT');

				$ValueRange = new Google_Service_Sheets_ValueRange();
				$ValueRange->setValues([['']]);
			
				$service->spreadsheets_values->update($spreadsheetId, 'Sheet1!A'. (3+$no), 
					$ValueRange, ['valueInputOption' => 'USER_ENTERED']
				);
			}
			
			$ValueRange = new Google_Service_Sheets_ValueRange();
			$ValueRange->setValues([[$rate]]);
			
			$service->spreadsheets_values->update($spreadsheetId, 'Sheet1!Q'. (3+$no), 
				$ValueRange, ['valueInputOption' => 'USER_ENTERED']
			);
		}
		
		$no++;
	}
}
