<?php
/**
 * make_assistants.php
 *
 * One-shot utility that creates a dedicated Assistant for each
 * Azure OpenAI deployment you care about.
 *
 * ---------  EDIT THE BLOCK BELOW  ---------
 */
$azureEndpoint = 'https://nhlbi-chat.openai.azure.com';
$azureKey      = 'c766f3be8420471dabccac63c2f75d8f';

/*  deployment-name  =>  friendly nick used in assistant name  */
$deployments = [
    'NHLBI-Chat-gpt-4o'   => '4o',
    'NHLBI-Chat-gpt-4.1'  => '4.1',
    'NHLBI-Chat-o3'       => 'o3',
    'NHLBI-Chat-o4-mini'  => 'o4-mini',
];

/*  You can tweak the default instructions & tools here         */
$defaultInstructions =
    'Answer normally.  When the user asks for a file, '.
    'create it with python-docx / openpyxl / python-pptx.';
$defaultTools       = [['type' => 'code_interpreter']];
$apiVersion         = '2024-08-01-preview';   // fixed for Assistants API
/* --------- END OF EDITING AREA --------- */

foreach ($deployments as $model => $nick) {

    $payload = [
        'name'        => "NHLBI-{$nick}-DocWriter",
        'model'       => $model,
        'instructions'=> $defaultInstructions,
        'tools'       => $defaultTools
    ];

    $assistant = azurePost(
        "$azureEndpoint/openai/assistants?api-version=$apiVersion",
        $payload,
        $azureKey
    );

    if (!empty($assistant['id'])) {
        echo str_pad($model, 25)
           ."  →  {$assistant['id']}\n";
    } else {
        $msg = $assistant['error']['message'] ?? json_encode($assistant);
        echo str_pad($model, 25)
           ."  →  ERROR  $msg\n";
    }
}

/* -------- helper ------------------------------------------------- */
function azurePost(string $url, array $json, string $key): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: '.$key
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode($json)
    ]);
    $resp  = curl_exec($ch);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
    curl_close($ch);

    return stripos($ctype, 'application/json') === 0
           ? json_decode($resp, true)
           : ['error'=>['message'=>$resp]];
}

