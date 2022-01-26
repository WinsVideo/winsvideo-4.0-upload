<?php

    include("/var/www/html/winsvideo.net/main/includes/config.php");

    function generateThumbnailUniqid($length = 11) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_-';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    $tempFilePath = $argv[1];
    $finalFilePath = $argv[2];
    $videoUrl = $argv[3]; 
    $remoteUrl = $argv[4];

    $ffmpegPath = "/usr/bin/ffmpeg";
    $ffprobePath = "/usr/bin/ffprobe";

    // update to db that the script has been executed
    $query = $con->prepare("UPDATE videos SET video_convert_status='1' WHERE video_url=:videoUrl");
    $query->bindParam(":videoUrl", $videoUrl);
    $query->execute();
    
    // convert video to mp4
    $convertCmd = "$ffmpegPath -i $tempFilePath $finalFilePath";
    $outputLog = array();
    exec($convertCmd, $outputLog, $returnCode);

    if($returnCode != 0) {
        // command failed
        foreach($outputLog as $line) {
            file_put_contents('./log_convert_video'.date("j.n.Y").'.log', $line, FILE_APPEND);
        }
    }

    // get the duration of the video and update it in the db
    $rawDuration = shell_exec("$ffprobePath -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 $finalFilePath");

    // format the duration
    $hours = floor($rawDuration / 3600);
    $mins = floor(($rawDuration - ($hours*3600)) / 60);
    $secs = floor($rawDuration % 60);

    $hours = ($hours < 1) ? "" : $hours . ":";
    $mins = ($mins < 10) ? "0" . $mins . ":" : $mins . ":";
    $secs = ($secs < 10) ? "0" . $secs : $secs;

    $duration = $hours.$mins.$secs;

    // generate thumbnails
        $thumbnailSize = "210x118";
        $numThumbnails = 3;
        $pathToThumbnail = "/var/www/html/winsvideo.net/main/uploads/videos/thumbnails";
        $remoteThumbnailUrl = "https://videos.winsvideo.net/uploads/videos/thumbnails";

        // $duration = $this->getVideoDuration($filePath);

        for($num = 1; $num <= $numThumbnails; $num++) {
            $imageName = uniqid() . ".jpg";
            $interval = ($rawDuration * 0.8) / $numThumbnails * $num;
            $fullThumbnailPath = "$pathToThumbnail/$videoUrl-$imageName";
            $remoteFullThumbnailPath = "$remoteThumbnailUrl/$videoUrl-$imageName";

            $cmd = "$ffmpegPath -i $finalFilePath -ss $interval -s $thumbnailSize -vframes 1 $fullThumbnailPath 2>&1";

            $outputLog = array();
            exec($cmd, $outputLog, $returnCode);

            if($returnCode != 0) {
                //Command failed
                foreach($outputLog as $line) {
                    echo $line . "<br>";
                }
            }

            $thumbnailUniqid = generateThumbnailUniqid();

            $query = $con->prepare("INSERT INTO thumbnails(thumbnail_uniqid, thumbnail_videoUrl, thumbnail_filePath, thumbnail_selected) VALUES(:thumbnail_uniqid, :thumbnail_videoUrl, :thumbnail_filePath, :thumbnail_selected)");
            $query->bindParam(":thumbnail_uniqid", $thumbnailUniqid);
            $query->bindParam(":thumbnail_videoUrl", $videoUrl);
            $query->bindParam(":thumbnail_filePath", $remoteFullThumbnailPath);
            $query->bindParam(":thumbnail_selected", $selected);

            $selected = $num == 1 ? 1 : 0;

            $success = $query->execute();

            if(!$success) {
                echo "Error inserting thumbail\n";
            }
        }

        // update all of the shit

        $query = $con->prepare("UPDATE videos SET video_convert_status='2',video_duration=:duration WHERE video_url=:videoUrl");
        $query->bindParam(":duration", $duration);
        $query->bindParam(":videoUrl", $videoUrl);
        $query->execute();
        
        unlink($tempFilePath);
?>