<!DOCTYPE html>
<html>

<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 600px;
            margin: 20px auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            background: #2563eb;
            color: #fff;
            padding: 10px 20px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }

        .content {
            padding: 20px;
        }

        .credentials-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }

        .credential-item {
            margin-bottom: 10px;
        }

        .label {
            font-weight: bold;
            color: #64748b;
            display: block;
            font-size: 0.875em;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .value {
            font-size: 1.1em;
            font-family: monospace;
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .footer {
            text-align: center;
            font-size: 0.8em;
            color: #666;
            margin-top: 20px;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        .button {
            display: inline-block;
            background: #2563eb;
            color: #ffffff;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h2>Welcome to Library Management System</h2>
        </div>
        <div class="content">
            <p>Hello <strong>{{ $user->name }}</strong>,</p>
            <p>An administrator account has been created for you. You can now access the Library Management System
                dashboard.</p>

            <div class="credentials-box">
                <div class="credential-item">
                    <span class="label">Email Address</span>
                    <span class="value">{{ $user->email }}</span>
                </div>
                <div class="credential-item">
                    <span class="label">Username</span>
                    <span class="value">{{ $user->username }}</span>
                </div>
                <div class="credential-item">
                    <span class="label">Password</span>
                    <span class="value">{{ $password }}</span>
                </div>
            </div>

            <p>For security reasons, we recommend that you change your password immediately after logging in.</p>

            <center>
                <a href="{{ $loginUrl }}" class="button">Login to Dashboard</a>
            </center>
        </div>
        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
            <p>&copy; {{ date('Y') }} Library Management System. All rights reserved.</p>
        </div>
    </div>
</body>

</html>