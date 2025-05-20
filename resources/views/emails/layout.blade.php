<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ config('app.name') }}</title>
    <style type="text/css">
        /* Base styles */
        body, html {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.5;
            color: #4a5568;
            background-color: #f7fafc;
        }
        
        /* Email container */
        .email-wrapper {
            width: 100%;
            background-color: #f7fafc;
            padding: 20px 0;
        }
        
        /* Header styles */
        .header {
            text-align: center;
            padding: 20px 0;
        }
        
        .header img {
            max-width: 200px;
            height: auto;
        }
        
        /* Content container */
        .content-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        /* Footer styles */
        .footer {
            text-align: center;
            padding: 20px;
            color: #a0aec0;
            font-size: 12px;
            background-color: #f7fafc;
        }
        
        /* Button styles */
        .button {
            display: inline-block;
            background-color: #4299e1;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 4px;
            font-weight: 600;
            margin: 15px 0;
        }
        
        /* Responsive styles */
        @media only screen and (max-width: 620px) {
            .content-container {
                width: 100% !important;
            }
        }
        
        @media only screen and (max-width: 480px) {
            .content-container {
                width: 100% !important;
            }
            
            .header img {
                max-width: 150px;
            }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="content-container">
            @if(config('app.logo'))
            <div class="header">
                <img src="{{ config('app.logo') }}" alt="{{ config('app.name') }} Logo">
            </div>
            @endif
            
            <div class="content">
                @yield('content')
            </div>
            
            <div class="footer">
                <p>&copy; {{ date('Y') }} {{ config('app.name') }}. Todos os direitos reservados.</p>
                <p>Este é um e-mail automático, por favor não responda.</p>
            </div>
        </div>
    </div>
</body>
</html> 