<?php
// ============================================================
//  TEST_GEMINI.PHP – command-line test of the Gemini connection
//
//  HOW TO RUN (after you pasted your API key into config.php):
//
//      php C:\xampp\htdocs\ai-chat\test_gemini.php
//
//  If you see "SUCCESS!" your API key and model work. Then you
//  can open the chat page in the browser.
// ============================================================

require_once __DIR__ . '/gemini.php';

echo 'Testing the Gemini API connection...' . PHP_EOL;
echo 'API key set   : ' . (GEMINI_API_KEY === '' ? 'NO  (paste your key in config.php first!)' : 'yes') . PHP_EOL;
echo 'Model         : ' . GEMINI_MODEL . PHP_EOL;
echo '--------------------------------------------' . PHP_EOL;

if (GEMINI_API_KEY === '') {
    echo 'Please open  C:\xampp\htdocs\ai-chat\config.php' . PHP_EOL;
    echo 'and paste your Gemini API key into GEMINI_API_KEY,' . PHP_EOL;
    echo 'then run this test again.' . PHP_EOL;
    exit;
}

try {
    $answer = askGemini('Say hello in one short sentence.');
    echo 'SUCCESS! Gemini answered:' . PHP_EOL;
    echo $answer . PHP_EOL;
} catch (Exception $e) {
    echo 'FAILED:' . PHP_EOL;
    echo $e->getMessage() . PHP_EOL;
}