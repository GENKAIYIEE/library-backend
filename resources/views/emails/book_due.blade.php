<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>PCLU Library Notification</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f4f7;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .header {
            background: linear-gradient(135deg, #020463 0%, #1a237e 100%);
            color: #ffffff;
            padding: 30px 20px;
            text-align: center;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
        }

        .header p {
            margin: 10px 0 0;
            opacity: 0.9;
            font-size: 14px;
        }

        .content {
            padding: 30px;
        }

        .alert-box {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }

        .alert-danger {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
        }

        .book-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .book-info strong {
            color: #020463;
        }

        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #6c757d;
        }

        .button {
            display: inline-block;
            background: #020463;
            color: #ffffff;
            padding: 12px 30px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            margin-top: 20px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>📚 PCLU Library</h1>
            <p>Punta Cana Learning University</p>
        </div>

        <div class="content">
            <p>Dear <strong>{{ $studentName }}</strong>,</p>

            @if($isOverdue)
                <div class="alert-box alert-danger">
                    <strong>⚠️ OVERDUE NOTICE</strong><br>
                    Your borrowed book is now <strong>past due</strong>. Please return it immediately to avoid additional
                    fines.
                </div>
            @else
                <div class="alert-box alert-warning">
                    <strong>📅 Friendly Reminder</strong><br>
                    Your borrowed book is due <strong>tomorrow</strong>. Please return it on time to avoid late fees.
                </div>
            @endif

            <div class="book-info">
                <p><strong>Book Title:</strong> {{ $bookTitle }}</p>
                <p><strong>Due Date:</strong> {{ \Carbon\Carbon::parse($dueDate)->format('F j, Y') }}</p>
                @if($isOverdue && $fineAmount > 0)
                    <p><strong>Current Fine:</strong> <span
                            style="color: #dc3545; font-weight: bold;">₱{{ number_format($fineAmount, 2) }}</span></p>
                @endif
            </div>

            <p>Please visit the library to return the book at your earliest convenience.</p>

            <p>Thank you for using the PCLU Library!</p>
        </div>

        <div class="footer">
            <p>This is an automated message from the PCLU Library Management System.</p>
            <p>Please do not reply to this email.</p>
        </div>
    </div>
</body>

</html>