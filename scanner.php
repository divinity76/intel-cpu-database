<?php

declare(strict_types=1);
// https://github.com/divinity76/hhb_.inc.php/blob/master/hhb_.inc.php
require_once('hhb_.inc.php');
const DB_FILE_NAME = 'databases/intel_cpu_database.json';
if (php_sapi_name() !== 'cli') {
    die("this script can only run from cli. look at the db to see how it's going, should be named: " . DB_FILE_NAME);
}
$scan_id_start = 0;
//9900K: $scan_id_start=186605-1;
$scan_id_start = 24544 - 1;
const SCAN_ID_MAX = 9999999;
function json_encode_pretty($data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined("JSON_UNESCAPED_LINE_TERMINATORS") ? JSON_UNESCAPED_LINE_TERMINATORS : 0));
}
function filter_characters(string $str): string
{
    return trim(strtr($str, array(
        '®' => '',
        '‡' => '',
        '™' => '',
        '*' => ''
    )));
}
function write_db_to_disk(array $data, string $db_file_name = DB_FILE_NAME): void
{
    $str = json_encode_pretty($data);
    if (($len = strlen($str)) !== ($written = file_put_contents($db_file_name, $str))) {
        throw new \RuntimeException("tried to write {$len} bytes to \"{$db_file_name}\" but could only write {$written} bytes!");
    }
}
hhb_init();
$hc = new hhb_curl('', true);
$hc->setopt_array(array(CURLOPT_TIMEOUT => 8));

if (!file_exists(DB_FILE_NAME)) {
    if (0 !== file_put_contents(DB_FILE_NAME, "", LOCK_EX)) {
        die("error: db file does not exist and unable to create db file!");
    }
}
if (true) {
    $db_lock = fopen(DB_FILE_NAME, "rb");
    if (!$db_lock) {
        die("error: unable to open db file!");
    }
    if (!flock($db_lock, LOCK_EX | LOCK_NB)) {
        die("ERROR: unable to get exclusive lock on db, scanner already running?");
    }
    register_shutdown_function(function () use (&$db_lock) {
        if (!flock($db_lock, LOCK_UN)) {
            fprintf(STDERR, "Warning: unable to unlock db_lock, wtf?...\n");
        }
    });
    $data = stream_get_contents($db_lock);
    if (empty($data)) {
        $data = array();
    } else {
        $data = json_decode($data, true);
        foreach ($data as $id => $unused) {
            if ($id > $scan_id_start) {
                $scan_id_start = $id;
            }
        }
        unset($id, $unused);
    }
}
$hc->setopt_array(array(CURLOPT_NOBODY => 1, CURLOPT_FOLLOWLOCATION => 0));
for ($id = $scan_id_start; $id < SCAN_ID_MAX; ++$id) {
    if (isset($data[$id])) {
        //already got info on this cpu.
        continue;
    }
    echo "\r{$id}: ";
    $success = false;
    for ($retry_loop_counter = 0; $retry_loop_counter < 100; ++$retry_loop_counter) {
        try {
            $hc->exec('https://ark.intel.com/content/www/us/en/ark/products/' . $id . '/C.html');
            $success = true;
            break;
        } catch (\RuntimeException $ex) {
            echo ".";
            sleep(60);
        }
    }
    if (!$success) {
        throw $ex;
    }
    unset($success, $retry_loop_counter);
    //$hc->setopt_array(array(CURLOPT_NOBODY=>false, CURLOPT_HTTPGET=>true));
    //hhb_var_dump($hc->getStdErr(),$hc->getStdOut()) & die();
    $code = $hc->getinfo(CURLINFO_HTTP_CODE);
    // found a product! but is it a CPU or is it something else? (like SSD)
    if ($code === 200) {
        echo "invalid product id"; // (great 404 there intel!)
        continue;
    } elseif ($code === 301) {
        echo " valid product id, but is it a processor?";
        // HTTP HEAD requests dont get all the info we need, sooooo
        $hc->setopt_array(array(CURLOPT_NOBODY => false, CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => true));
        $success = false;
        for ($retry_loop_counter = 0; $retry_loop_counter < 100; ++$retry_loop_counter) {
            try {
                $html = $hc->exec()->getStdOut();
                $success = true;
                break;
            } catch (\RuntimeException $ex) {
                echo ".";
                sleep(60);
            }
        }
        if (!$success) {
            throw $ex;
        }
        unset($success, $retry_loop_counter);
        $hc->setopt_array(array(CURLOPT_HTTPGET => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOBODY => true));
        $domd = @DOMDocument::loadHTML($html);
        $xp = new DOMXPath($domd);
        $title = $domd->getElementsByTagName("title");
        if ($title->length < 1) {
            hhb_var_dump($hc->getStdErr(), $hc->getStdOut());
            throw new \RuntimeException("COULD NOT PARSE TITLE FROM INTEL HTML! (details printed in stdout)");
        }
        $title = trim($title->item(0)->textContent);
        if (false === stripos($title, 'Processor')) {
            // it's a product, but it's not a processor.
            echo " " . "not a processor. title: {$title}", PHP_EOL;
            continue;
        }
        echo " " . "yes! found processor, title: \"{$title}\"";
        $specs_ele = $xp->query('//div[contains(@class,"specs-section") and contains(@class,"active")]')->item(0);
        $sections = $xp->query(".//section", $specs_ele);
        //hhb_var_dump($sections) & die();
        $name = $title;
        if (endsWith($name, 'Product Specifications')) {
            $name = trim(substr($name, 0, strlen($name) - strlen('Product Specifications')));
        }
        $data[$id]['name'] = filter_characters($name);
        foreach ($sections as $section) {
            $section_name = filter_characters($xp->query(".//div[contains(@class,'subhead')]//h2", $section)->item(0)->textContent);
            //hhb_var_dump($section_name) & die();
            $section_value_list = $xp->query(".//ul[contains(@class,'specs-list')]/li", $section);
            foreach ($section_value_list as $give_me_a_name) {
                $name = filter_characters($xp->query(".//span[contains(@class,'label')]", $give_me_a_name)->item(0)->textContent);
                $value = filter_characters($xp->query(".//span[contains(@class,'value')]", $give_me_a_name)->item(0)->textContent);
                $data[$id][$section_name][$name] = $value;
            }
        }
        echo " " . "parsed. adding to db..";
        write_db_to_disk($data);
        echo ". done!\n";
    } else {
        hhb_var_dump($hc->getStdErr(), $hc->getStdOut());
        throw new \RuntimeException("ERROR: expected HTTP 200 OR HTTP 301, BUT GOT HTTP {$code} (details printed in stdout)");
    }
}
function endsWith($haystack, $needle): bool
{
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }
    return (substr($haystack, -$length) === $needle);
}
