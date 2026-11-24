@props(['url'])
<tr>
<td class="header" style="padding: 24px 0; text-align: center;">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none; color: #256331; text-align: center;">
<span style="display: block; font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 700; color: #256331; line-height: 1.3; letter-spacing: -0.01em; margin: 0;">
{{ trim((string) $slot) !== '' ? trim((string) $slot) : config('institution.name', 'Servitech Institute Asia Inc.') }}
</span>
</a>
</td>
</tr>
