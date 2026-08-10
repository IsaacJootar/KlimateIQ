@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'KlimateIQ')
<span class="logo-mark">&#9679;</span> KlimateIQ
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
