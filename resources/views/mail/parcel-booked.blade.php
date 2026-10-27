<!doctype html>
<html lang="en">
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden">
      <tr><td style="background:#0d0d0f;padding:20px 28px;color:#ffffff;font-size:18px;font-weight:bold">{{ $company }}</td></tr>
      <tr><td style="height:4px;background:#F38121"></td></tr>
      <tr><td style="padding:28px">
        <p style="margin:0 0 6px;font-size:13px;color:#F38121;font-weight:bold">Parcel booked</p>
        <h1 style="margin:0 0 14px;font-size:22px">Hi {{ $parcel->receiver_name }}, a parcel is on its way to you</h1>
        <p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#3f3f46">A parcel has been booked for delivery to you. Here are the details. Keep your tracking number to follow its progress.</p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e4e7;border-radius:8px;font-size:14px">
          @foreach($details as $label => $value)
            <tr>
              <td style="padding:10px 14px;color:#71717a;width:42%;vertical-align:top;{{ $loop->last ? '' : 'border-bottom:1px solid #e4e4e7;' }}">{{ $label }}</td>
              <td style="padding:10px 14px;vertical-align:top;{{ $loop->first ? 'font-family:Menlo,Consolas,monospace;font-weight:bold;color:#c2410c;' : '' }}{{ $loop->last ? '' : 'border-bottom:1px solid #e4e4e7;' }}">{{ $value }}</td>
            </tr>
          @endforeach
        </table>

        <p style="margin:24px 0" align="center">
          <a href="{{ $trackUrl }}" style="display:inline-block;background:#F38121;color:#141414;text-decoration:none;font-weight:bold;padding:13px 24px;border-radius:8px">Track your parcel</a>
        </p>
        <p style="margin:0;font-size:13px;line-height:1.5;color:#71717a">If any of these details are wrong, call us on 0810 123 3538 or reply to this email before the parcel is out for delivery.</p>
      </td></tr>
      <tr><td style="padding:16px 28px;background:#fafafa;font-size:12px;color:#a1a1aa">{{ $company }} · 19b Bush Street, Mende Maryland · info@stallion-king.com</td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
