@extends('emails.layout')

@section('content')
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background: #f9f9f9; padding: 40px 0;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                <tr>
                    <td style="padding: 40px 40px 20px 40px; text-align: center;">
                        <h1 style="color: #2d3748; font-size: 28px; margin-bottom: 10px;">Bem-vindo ao Sistema!</h1>
                        <p style="color: #4a5568; font-size: 18px; margin-bottom: 0;">Olá, <strong>{{ $name }}</strong>!</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 0 40px 30px 40px;">
                        <p style="color: #4a5568; font-size: 16px;">Sua conta foi criada com sucesso. Aqui estão seus dados de acesso:</p>
                        <table cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0 30px 0; width: 100%;">
                            <tr>
                                <td style="color: #2d3748; font-size: 16px; padding: 8px 0;">E-mail:</td>
                                <td style="color: #2d3748; font-size: 16px; padding: 8px 0;"><strong>{{ $email }}</strong></td>
                            </tr>
                            <tr>
                                <td style="color: #2d3748; font-size: 16px; padding: 8px 0;">Senha inicial:</td>
                                <td style="color: #2d3748; font-size: 16px; padding: 8px 0;"><strong>{{ $password }}</strong></td>
                            </tr>
                        </table>
                        <p style="color: #4a5568; font-size: 15px;">Por motivos de segurança, recomendamos que você altere sua senha após o primeiro acesso.</p>
                        <p style="color: #4a5568; font-size: 15px;">Se você não solicitou este cadastro, por favor ignore este e-mail.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 0 40px 40px 40px; text-align: center;">
                        <a href="{{ url('/') }}" style="display: inline-block; background: #3182ce; color: #fff; text-decoration: none; padding: 12px 32px; border-radius: 4px; font-size: 16px;">Acessar o Sistema</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 0 40px 30px 40px; text-align: center; color: #a0aec0; font-size: 13px;">
                        &copy; {{ date('Y') }} Seu Sistema. Todos os direitos reservados.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
@endsection 