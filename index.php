<?php
$response = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Replace this with your actual Discord webhook URL
    $discord_webhook_url = include 'config.local.php' ?? include 'config.php' ?? '';
    if(empty($discord_webhook_url)) die('Set your Discord webhook URL in config.php or config.local.php');

    // Get the Audible URL from the form
    $audible_url = $_POST['audible_url'] ?? '';
    $audible_url_arr = explode('?', $audible_url);
    $audible_url = $audible_url_arr[0];

    // Validate the URL (optional)
    if (!filter_var($audible_url, FILTER_VALIDATE_URL)) {
        die('Invalid URL');
    }

    // You should implement scraping the Audible website here, but I'll skip it to respect Audible's policies.

    // region Get page
    $html = file_get_contents($audible_url);
    $htmlLine = str_replace("\n", '', $html);
    // endregion

    // region Get OpenGraph tags
    $og_matches = [];
    preg_match_all('/.*<meta property="og:(\w*)" content="(.*)" \/>.*/', $html, $og_matches);
    $og_items = [];
    for($i = 0; $i < count($og_matches[0]); $i++) {
        $k = $og_matches[1][$i];
        $v = $og_matches[2][$i];
        $og_items[$k] = $v;
    }
    // endregion

    // region Get image
    $image_match = '';
    $image_url = preg_match(
        '/.*(https:\/\/m.media-amazon.com\/images\/I\/([^.]*?)\.).*/',
        $og_items['image'],
        $image_match
    );
    // endregion

    // region Get author
    $author_match = [];
    preg_match('/.*authorLabel".*?By:.*?>(.*?)<\/a>.*/', $htmlLine, $author_match);
    $author = trim($author_match[1]);
    // endregion

    // region Get narrator
    $narrator_match = [];
    preg_match('/.*narratorLabel".*?Narrated by:.*?>(.*?)<\/a>.*/', $htmlLine, $narrator_match);
    $narrator = trim($narrator_match[1]);
    // endregion

    // region Get series
    $series_match = [];
    preg_match('/.*seriesLabel".*?Series:.*?>(.*?)<\/a>(.*?)<\/li>.*/', $htmlLine, $series_match);
    $series = count($series_match) == 3 ? trim($series_match[1]).trim($series_match[2]) : '';
    // endregion

    // region Get length
    $runtime_match = [];
    preg_match('/.*runtimeLabel".*?Length:(.*?)<\/li>.*/', $htmlLine, $runtime_match);
    $runtime = trim($runtime_match[1]);
    // endregion

    // Sample data (replace this with actual scraped data)
    $book_title = $og_items['title'];
    $cover_art = $image_match[1]. '._SL500_.jpg';
    $description = trim(str_replace('Check out this great listen on Audible.com.', '', $og_items['description']));
    $book_url = $og_items['url'];
    $book_url_parts = explode('/', $book_url);
    $book_id = array_pop($book_url_parts);

    // region Get publisher
    $sample_match = [];
    preg_match('/.*id="sample-player-'.$book_id.'".*?data-mp3="(.*?)".*/', $htmlLine, $sample_match);
    $sample_url = $sample_match[1];
    $attach_sample = false;
    $attachment = '';
    $boundary = bin2hex(random_bytes(15));
    $file_contents = '';
    if (filter_var($sample_url, FILTER_VALIDATE_URL)) {
        $file_contents = file_get_contents($sample_url);
        if($file_contents) {
            $attach_sample = true;
            $attachment = "\r\n--$boundary\r\nContent-Disposition: form-data; name=files[0]; filename=sample.mp3\r\nContent-Type: audio/mpeg\r\n\r\n$file_contents";
        }
    }
    // endregion

    // Prepare the data to be sent to Discord
    $description_items = [
        "# [$book_title]($book_url)",
        "**Author**: $author",
        "**Narrated by**: $narrator",
        "**Series**: $series",
        "**Length**: $runtime",

    ];
    $discord_data = [
        'content' => implode("\n", $description_items),
        'embeds' => [
            [
                'description' => "\n**Description**: $description",
                'thumbnail' => [
                    'url' => $cover_art,
                ],
            ],
        ]
    ];
    if ($attach_sample) {
        $discord_data['attachments'] = [[
            'id'=>0,
            'description' => 'Sample audio clip of the book',
            'filename' => build_filename($book_title)
        ]];
    }

    // Send data to Discord webhook
    $payload = "--$boundary\r\nContent-Disposition: form-data; name=payload_json\r\nContent-Type: application/json\r\n\r\n".json_encode($discord_data).$attachment."\r\n--$boundary--\r\n";
    $ch = curl_init($discord_webhook_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data; boundary='.$boundary.'; Content-Length: '.strlen($payload).';']);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    if($response !== false) {
        file_put_contents('links.txt', $audible_url."\n", FILE_APPEND);
    }
}

function build_filename(string $string): string
{
    $string = preg_replace('/[^a-z0-9\s\-]/i', '', $string);
    $string = preg_replace('/\s/', '_', $string);
    $string = preg_replace('/__+/', '_', $string);
    $string = strtolower(trim($string, '_'));
    if(strlen($string) > 60) {
        $string = substr($string, 0, 60);
    }
    return "{$string}_sample.mp3";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Audible Book Data to Discord</title>
    <style>
        body {
            font-family: sans-serif;
        }
    </style>
</head>
<body>
<h1>Enter Audible Book URL</h1>
<form action="" method="post">
    <input type="text" name="audible_url" placeholder="Enter Audible URL" />
    <input type="submit" value="Scrape and Send to Discord" />
</form>
<p><strong>Response</strong>: <?=$response?></p>
</body>
</html>