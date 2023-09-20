<?php
$response = '';
$discord_webhook_url = '';
header('Content-Type: application/json');

//if (file_exists('config.php')) {
//    include 'config.php';
//}

if (file_exists('config.local.php')) {
    include 'config.local.php';
} elseif (file_exists('config.php')) {
    include 'config.php';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if($_POST['password'] != $mypass){

        $response = '<p>Password Incorrect</p>';
        die(json_encode(['message'=>$response]));
       
    }else{
        // Load Discord webhook
        if (empty($discord_webhook_url)) {
            die(json_encode(['message' => 'Set your Discord webhook URL in config.php or config.local.php']));
        }
        

        // Get the Audible URL from the form
        $audible_url = urldecode($_POST['audible_url'] ?? '');
        $audible_url_arr = explode('?', $audible_url);
        $audible_url = $audible_url_arr[0];

        //Finished Date
        if($_POST['finished_date'] == ""){
            $finished_date = date('d/m/Y');
        }else{
            $input_date = $_POST['finished_date'];
            $date_object = DateTime::createFromFormat('Y/m/d', $input_date);
            $finished_date = $date_object->format('d/m/Y');
            //$finished_date = date("d/m/Y", strtotime($_POST['finished_date']));
        }
        // endregion
        
        //Book Rating
        if($_POST['book_rating'] == ""){
            $book_rating = "?";
        }else{
            $book_rating = $_POST['book_rating'];
        }

        // Should we repost?
        $repost = boolval($_POST['repost'] ?? false);
        if (!filter_var($audible_url, FILTER_VALIDATE_URL)) {
            die(json_encode(['message'=>"Invalid URL: $audible_url"]));
        } else {
            echo scrapeAndPost($audible_url, $finished_date, $book_rating, $discord_webhook_url, $repost);
        }
    }
}

function scrapeAndPost(string $audible_url, string $finished_date, string $book_rating, string $discord_webhook_url, bool $isRepost) {
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
        $og_items[$k] = html_entity_decode($v);

    }
    // endregion

    // region Get image
    $image_match = '';
    preg_match(
        '/.*(https:\/\/m.media-amazon.com\/images\/I\/([^.]*?)\.).*/',
        $og_items['image'],
        $image_match
    );
    $image_url = $image_match[1]. '._SL500_.jpg';
    // endregion

    // region Get author
    $author_match = [];
    preg_match('/.*authorLabel".*?By:.*?>(.*?)<\/a>.*/', $htmlLine, $author_match);
    $author = html_entity_decode(trim($author_match[1]));
    // endregion

    // region Get narrator
    $narrator_match = [];
    preg_match('/.*narratorLabel".*?>(.*?)<\/li>.*/', $htmlLine, $narrator_match);
    $narrator = count($narrator_match) == 2
        ? trim(strip_tags(str_replace('Narrated by:', '', $narrator_match[1])))
        : '';
    $narrator = html_entity_decode(preg_replace('/\s\s+/', ' ', $narrator));
    // endregion

    // region Get series
    $series_match = [];
    preg_match('/.*seriesLabel".*?>(.*?)<\/li>.*/', $htmlLine, $series_match);
    $series = count($series_match) == 2
        ? trim(strip_tags(str_replace('Series:', '', $series_match[1])))
        : '';
    $series = html_entity_decode(preg_replace('/\s\s+/', ' ', $series));
    // endregion

    // region Get length
    $runtime_match = [];
    preg_match('/.*runtimeLabel".*?Length:(.*?)<\/li>.*/', $htmlLine, $runtime_match);
    $runtime = html_entity_decode(trim($runtime_match[1]));
    // endregion

    // Sample data (replace this with actual scraped data)
    $book_title = $og_items['title'];
    $description = trim(str_replace('Check out this great listen on Audible.com.', '', $og_items['description']));
    $book_url = $og_items['url'];
    $book_url_parts = explode('/', $book_url);
    $book_id = array_pop($book_url_parts);

    // region Get publisher
    $sample_match = [];
    preg_match('/.*id="sample-player-'.$book_id.'".*?data-mp3="(.*?)".*/', $htmlLine, $sample_match);
    $sample_url = $sample_match[1];
    $boundary = bin2hex(random_bytes(15));
    $file_contents = '';

    $attachmentsItems = [];
    $attachmentsData = [];

    if (filter_var($sample_url, FILTER_VALIDATE_URL)) {
        $attachment = attach_file($sample_url, $boundary, 'files[1]', 'sample.mp3', 'audio/mpeg');
        if(!empty($attachment)) {
            $attachmentsData[] = $attachment;
            $attachmentsItems[] = [
                'id'=>1,
                'description' => 'Sample audio clip of the book',
                'filename' => build_filename($book_title, "sample.mp3")
            ];
        }
    }
    // endregion

    // Prepare the data to be sent to Discord

    $description_items = [];
    $description_items[] = "Book Finished: **$book_title**";
    if(!empty($series)) $description_items[] = "Series: **$series**";
    $description_items[] = "Author: **$author**";
    $description_items[] = "Narrated by: **$narrator**";
    if(!empty($runtime)) $description_items[] = "Length: **$runtime**";
    $description_items[] = "Date Finished: **$finished_date**";
    $description_items[] = "My Rating: **$book_rating out of 10**";
    $description_items[] = "$book_url";

    $discord_data = [
        'content' => implode("\n", $description_items)."\n\n",
            'embeds' => [
                [
                    'description' => "\n**Description**: $description",
                    'image' => [
                        'url' => $image_url,
                    ], 
                                      
                ]
            ]
    ];
    if(count($attachmentsItems) > 0) {
        $discord_data['attachments'] = $attachmentsItems;
    }

    // Send data to Discord webhook
    $payload = build_payload($boundary, $discord_data, $attachmentsData);
    $ch = curl_init($discord_webhook_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data; boundary='.$boundary.'; Content-Length: '.strlen($payload).';']);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    if($response !== false && !$isRepost) {
        $dateTime = DateTime::createFromFormat('d/m/Y', $finished_date);
        $formatted_date = $dateTime->format('Y/m/d');
        file_put_contents('links.txt', $audible_url . ',' . $formatted_date . ',' . $book_rating . "\n", FILE_APPEND);
    }
    return $response;
}

function build_payload(string $boundary, array $jsonData, array $attachmentsData): string
{
    $payload = attach_file($jsonData, $boundary, 'payload_json', 'payload.json', 'application/json');
    $payload .= implode('', $attachmentsData);
    $payload .= "--$boundary--\r\n";
    return $payload;
}

function attach_file(string|array $filePathOrJSON, string $boundary, string $name, string $fileName, string $contentType): string
{
    if(is_array($filePathOrJSON)) {
        $data = json_encode($filePathOrJSON);
        return "--$boundary\r\nContent-Disposition: form-data; name=$name\r\nContent-Type: $contentType\r\n\r\n$data\r\n";
    } else {
        $data = file_get_contents($filePathOrJSON);
        return "--$boundary\r\nContent-Disposition: form-data; name=$name; filename=$fileName\r\nContent-Type: $contentType\r\n\r\n$data\r\n";
    }
}

function build_filename(string $string, string $extension): string
{
    $string = preg_replace('/[^a-z0-9\s\-]/i', '', $string);
    $string = preg_replace('/\s/', '_', $string);
    $string = preg_replace('/__+/', '_', $string);
    $string = strtolower(trim($string, '_'));
    if(strlen($string) > 60) {
        $string = substr($string, 0, 60);
    }
    return "{$string}_$extension";
}
