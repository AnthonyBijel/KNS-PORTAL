<?php

require "../db.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $announcementType = $_POST["announcement_type"] ?? "";
    $description = trim($_POST["description"] ?? "");

    $mediaPath = null;
    $mediaType = null;

    // Validate announcement type
    $allowedTypes = ["Academic", "Events", "Guidelines"];

    if (!in_array($announcementType, $allowedTypes)) {
        die("Invalid announcement type.");
    }

    if ($description === "") {
        die("Description is required.");
    }


    // MEDIA UPLOAD
    if (
        isset($_FILES["media"]) &&
        $_FILES["media"]["error"] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES["media"]["error"] !== UPLOAD_ERR_OK) {
            die("Upload failed.");
        }

        $file = $_FILES["media"];

        $mime = mime_content_type($file["tmp_name"]);

        $allowedFiles = [
            "image/jpeg" => "jpg",
            "image/png"  => "png",
            "image/gif"  => "gif",
            "image/webp" => "webp",
            "video/mp4"  => "mp4",
            "video/webm" => "webm"
        ];

        if (!isset($allowedFiles[$mime])) {
            die("Invalid image or video file.");
        }

        if (str_starts_with($mime, "image/")) {
            $mediaType = "image";
        } else {
            $mediaType = "video";
        }

        $extension = $allowedFiles[$mime];

        $fileName =
            uniqid("announcement_", true) .
            "." .
            $extension;

        $uploadFolder = __DIR__ . "/../uploads/announcements/";

        if (!is_dir($uploadFolder)) {
            mkdir($uploadFolder, 0777, true);
        }

        $destination =
            $uploadFolder .
            $fileName;

        if (!move_uploaded_file(
            $file["tmp_name"],
            $destination
        )) {
            die("Failed to save media.");
        }

        $mediaPath =
            "uploads/announcements/" .
            $fileName;
    }


    // SAVE TO DATABASE
    $sql = "
        INSERT INTO announcements
        (
            announcement_type,
            description,
            media_path,
            media_type
        )
        VALUES
        (
            :announcement_type,
            :description,
            :media_path,
            :media_type
        )
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":announcement_type" => $announcementType,
        ":description"       => $description,
        ":media_path"        => $mediaPath,
        ":media_type"        => $mediaType
    ]);

    header("Location: dashboard.html?posted=success");
    exit;
}
