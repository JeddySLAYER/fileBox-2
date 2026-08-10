@php
    /** @var \App\Models\User $user */
    $isReset = ($reason ?? 'created') === 'reset';
    $brand = '#D73A31';
    $ink = '#1F2430';
    $muted = '#6B7280';
    $border = '#E5E7EB';
    $bg = '#F7F8FA';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isReset ? 'Nouveau mot de passe temporaire' : 'Bienvenue sur FileBox' }}</title>
</head>
<body style="margin:0;padding:0;background:{{ $bg }};font-family:'Segoe UI',Arial,Helvetica,sans-serif;color:{{ $ink }};">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:{{ $bg }};padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid {{ $border }};border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="background:{{ $brand }};padding:20px 28px;">
                        <p style="margin:0;font-size:13px;letter-spacing:0.14em;text-transform:uppercase;color:rgba(255,255,255,0.75);">
                            FileBox · GED
                        </p>
                        <h1 style="margin:8px 0 0;font-size:22px;line-height:1.3;font-weight:600;color:#ffffff;">
                            {{ $isReset ? 'Mot de passe réinitialisé' : 'Votre compte est prêt' }}
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px;">
                        <p style="margin:0 0 16px;font-size:15px;line-height:1.55;">
                            Bonjour <strong>{{ $user->name }}</strong>,
                        </p>

                        <p style="margin:0 0 20px;font-size:15px;line-height:1.55;color:{{ $muted }};">
                            @if ($isReset)
                                Un administrateur a réinitialisé votre mot de passe FileBox.
                                Utilisez le mot de passe temporaire ci-dessous pour vous reconnecter.
                            @else
                                Un compte FileBox a été créé pour vous.
                                Voici vos identifiants de première connexion.
                            @endif
                        </p>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border:1px solid {{ $border }};border-radius:10px;background:{{ $bg }};">
                            <tr>
                                <td style="padding:16px 18px;">
                                    <p style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;color:{{ $muted }};">
                                        Identifiant
                                    </p>
                                    <p style="margin:0 0 14px;font-size:15px;font-weight:600;word-break:break-all;">
                                        {{ $user->email }}
                                    </p>
                                    <p style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;color:{{ $muted }};">
                                        Mot de passe temporaire
                                    </p>
                                    <p style="margin:0;font-size:18px;font-weight:700;letter-spacing:0.04em;font-family:Consolas,'Courier New',monospace;color:{{ $brand }};">
                                        {{ $temporaryPassword }}
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 24px;">
                            <tr>
                                <td style="padding:12px 14px;border-left:3px solid {{ $brand }};background:#FAECEC;">
                                    <p style="margin:0;font-size:13px;line-height:1.5;color:{{ $ink }};">
                                        Ce mot de passe expire dans <strong>24 heures</strong>.
                                        Vous devrez le changer dès votre première connexion.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 8px;">
                            <tr>
                                <td style="border-radius:8px;background:{{ $brand }};">
                                    <a href="{{ $loginUrl }}"
                                       style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
                                        Se connecter à FileBox
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:{{ $muted }};">
                            Si le bouton ne fonctionne pas, copiez ce lien :<br>
                            <a href="{{ $loginUrl }}" style="color:{{ $brand }};word-break:break-all;">{{ $loginUrl }}</a>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 28px 22px;border-top:1px solid {{ $border }};">
                        <p style="margin:0;font-size:12px;line-height:1.5;color:{{ $muted }};">
                            Ne partagez jamais ce mot de passe. Si vous n’êtes pas à l’origine de cette demande,
                            contactez votre administrateur FileBox.
                        </p>
                        <p style="margin:12px 0 0;font-size:12px;color:{{ $muted }};">
                            — L’équipe FileBox · E-business Afrique
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
