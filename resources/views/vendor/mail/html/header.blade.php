@props(['url'])
<tr>
<td class="header" style="padding: 24px 0; text-align: center;">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none; color: inherit; text-align: left; vertical-align: middle;">
<span style="display: inline-block; vertical-align: middle; margin-right: 12px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 4px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05); width: 44px; height: 44px; box-sizing: border-box; text-align: center;">
<img src="{{ rtrim(config('app.url'), '/') . '/images/brand/servitech-crest.webp' }}" width="34" height="34" alt="" aria-hidden="true" style="display: inline-block; margin: 0 auto; border: 0; outline: none; text-decoration: none; width: 34px; height: 34px; object-fit: contain; vertical-align: middle;">
</span>
<span style="display: inline-block; vertical-align: middle; text-align: left;">
<span style="display: block; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; font-weight: 700; color: #1e3a8a; line-height: 1.25; margin: 0;">
{{ config('institution.name', 'Servitech Institute Asia Inc.') }}
</span>
<span style="display: block; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 11px; font-weight: 500; color: #64748b; line-height: 1.4; margin-top: 3px;">
<img src="{{ rtrim(config('app.url'), '/') . '/talalogo.png' }}" width="12" height="12" alt="" aria-hidden="true" style="display: inline-block; vertical-align: -1px; margin-right: 4px; width: 12px; height: 12px; border-radius: 3px; object-fit: contain;">Powered by {{ config('app.name', 'TALA') }}
</span>
</span>
</a>
</td>
</tr>
