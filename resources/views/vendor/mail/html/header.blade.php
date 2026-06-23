@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ rtrim((string) config('app.url'), '/') }}/shoutrrr.png" class="logo" alt="{{ config('app.name', 'Shoutrrr') }} Logo">
</a>
</td>
</tr>
