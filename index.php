<?php
// main.php — home page
$title = "האתר הפנימי של המשפחה";
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f6fb;
            color: #222;
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
            box-sizing: border-box;
        }
        h1 {
            font-size: 2rem;
            margin-bottom: 40px;
        }
        .vocab-btn {
            display: inline-block;
            font-size: 2.2rem;
            font-weight: bold;
            color: #fff;
            background: #2f5597;
            padding: 30px 50px;
            border-radius: 16px;
            text-decoration: none;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
            transition: transform 0.15s, background 0.15s;
        }
        .vocab-btn:hover {
            background: #1f3d70;
            transform: scale(1.05);
        }
        .small-link {
            margin-top: 50px;
            font-size: 0.85rem;
        }
        .small-link a {
            color: #888;
        }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($title) ?></h1>

    <a class="vocab-btn" href="vocab.php">📚 Take the vocabulary quiz</a>

    <div class="small-link">
        <a href="phpinfo.php">server info</a>
    </div>
</body>
</html>
