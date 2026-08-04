{{-- Chromeless YouTube player (resources/js/clean-youtube.js mounts it).

     Custom controls only — play/pause, mute/volume, timeline, fullscreen.
     No YouTube logo, channel avatar, CC, settings, or related videos.

     Props:
       url      — any YouTube URL form (watch/shorts/live/youtu.be/embed…)
       videoId  — pass the id directly instead of a url
       title    — accessibility title
       live     — live stream: hides the timeline, shows a LIVE badge
       poster   — custom pre-play poster image URL; overrides YouTube's
                  thumbnails (which can be a grey placeholder for fresh
                  or unlisted uploads)

     Sizing comes from the caller via class="" (e.g. absolute inset-0 in an
     aspect-video wrapper, or w-full h-full). Renders nothing for non-YouTube
     URLs — callers keep their <video> fallback for direct files. --}}
@props(['url' => null, 'videoId' => null, 'title' => '', 'live' => false, 'poster' => null])

@php $ytcId = $videoId ?: youtube_video_id($url); @endphp

@if($ytcId)
    <div {{ $attributes }}
         data-yt-clean
         data-yt-id="{{ $ytcId }}"
         @if($poster) data-poster="{{ $poster }}" @endif
         @if($live) data-live="1" @endif
         @if($title !== '') data-title="{{ $title }}" @endif
         role="region"
         aria-label="{{ $title !== '' ? $title : 'Video' }}"></div>
@endif
