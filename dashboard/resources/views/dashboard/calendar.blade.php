@extends('layouts.app')

@section('title', 'Content Calendar')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-white"><i class="bi bi-calendar3 me-2"></i>Content Calendar</h1>
        <p class="text-secondary small mb-0">View and manage your scheduled content</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" onclick="calendarNav('prev')">
            <i class="bi bi-chevron-left"></i>
        </button>
        <span id="calendarTitle" class="text-white align-self-center fw-semibold px-2"></span>
        <button class="btn btn-outline-secondary btn-sm" onclick="calendarNav('next')">
            <i class="bi bi-chevron-right"></i>
        </button>
        <button class="btn btn-outline-info btn-sm ms-1" onclick="calendarNav('today')">Today</button>
    </div>
</div>

{{-- Platform Legend --}}
<div class="d-flex gap-2 mb-3 flex-wrap">
    <span class="badge" style="background:#E1306C;">Instagram</span>
    <span class="badge" style="background:#1877F2;">Facebook</span>
    <span class="badge" style="background:#FF0000;">YouTube</span>
    <span class="badge" style="background:#0A66C2;">LinkedIn</span>
    <span class="badge" style="background:#000;border:1px solid rgba(255,255,255,.2);">TikTok</span>
    <span class="badge" style="background:#1DA1F2;">Twitter/X</span>
    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Scheduled</span>
</div>

{{-- Calendar Grid --}}
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-bordered mb-0" id="calendarGrid">
                <thead>
                    <tr>
                        @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day)
                            <th class="text-center text-secondary py-2 small">{{ $day }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody id="calendarBody">
                    {{-- Populated by JS --}}
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Event Detail Modal --}}
<div class="modal fade" id="eventModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:#16213e;border:1px solid rgba(255,255,255,0.1);">
            <div class="modal-header border-0">
                <h5 class="modal-title text-white" id="eventModalTitle">Post Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-secondary" id="eventModalBody"></div>
        </div>
    </div>
</div>

<style>
#calendarGrid th { width: 14.285%; }
#calendarGrid td {
    height: 110px; vertical-align: top; padding: 4px;
    background: rgba(255,255,255,0.02);
    border-color: rgba(255,255,255,0.08) !important;
}
#calendarGrid td:hover { background: rgba(255,255,255,0.05); }
.cal-day-num { font-size: .8rem; color: rgba(255,255,255,0.45); margin-bottom: 3px; }
.cal-day-today .cal-day-num {
    background: #6c63ff; color: #fff; border-radius: 50%;
    width: 24px; height: 24px;
    display: inline-flex; align-items: center; justify-content: center;
}
.cal-event {
    font-size: .7rem; padding: 2px 5px; border-radius: 4px; margin-bottom: 2px;
    cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #fff;
}
.cal-event:hover { opacity: .8; }
.cal-event.scheduled { border: 1px dashed #ffc107; background: rgba(255,193,7,.15) !important; color: #ffc107; }
.cal-day-other { opacity: .3; }
</style>

<script>
let currentDate = new Date();
const allEvents = @json($events ?? []);

function renderCalendar(year, month) {
    const firstDay = new Date(year, month - 1, 1);
    const lastDay  = new Date(year, month, 0);
    const startDay = firstDay.getDay();
    const totalDays = lastDay.getDate();
    const today = new Date();
    const prevMonthDays = new Date(year, month - 1, 0).getDate();

    document.getElementById('calendarTitle').textContent =
        firstDay.toLocaleString('default', { month: 'long', year: 'numeric' });

    let html = '';
    let day = 1;

    for (let week = 0; week < 6; week++) {
        html += '<tr>';
        for (let d = 0; d < 7; d++) {
            const idx = week * 7 + d;
            if (idx < startDay) {
                const pd = prevMonthDays - startDay + idx + 1;
                html += `<td class="cal-day-other"><div class="cal-day-num">${pd}</div></td>`;
            } else if (day > totalDays) {
                const nd = day - totalDays;
                day++;
                html += `<td class="cal-day-other"><div class="cal-day-num">${nd}</div></td>`;
            } else {
                const isToday = (day === today.getDate() && month === today.getMonth() + 1 && year === today.getFullYear());
                const dateStr = `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
                const dayEvents = allEvents.filter(e => e.start && e.start.startsWith(dateStr));

                html += `<td class="${isToday ? 'cal-day-today' : ''}">`;
                html += `<div class="cal-day-num">${day}</div>`;
                dayEvents.forEach(ev => {
                    const cls = ev.status === 'scheduled' ? 'scheduled' : '';
                    const bg  = ev.status === 'scheduled' ? '' : `background:${ev.color};`;
                    const icon = ev.status === 'scheduled' ? '<i class="bi bi-clock me-1"></i>' : '';
                    html += `<div class="cal-event ${cls}" style="${bg}" onclick='showEvent(${JSON.stringify(ev)})'>${icon}${ev.title}</div>`;
                });
                html += '</td>';
                day++;
            }
        }
        html += '</tr>';
        if (day > totalDays && week >= 3) break;
    }

    document.getElementById('calendarBody').innerHTML = html;
}

function calendarNav(action) {
    if (action === 'prev')  currentDate.setMonth(currentDate.getMonth() - 1);
    else if (action === 'next') currentDate.setMonth(currentDate.getMonth() + 1);
    else currentDate = new Date();
    renderCalendar(currentDate.getFullYear(), currentDate.getMonth() + 1);
}

function showEvent(ev) {
    document.getElementById('eventModalTitle').textContent = ev.title;
    document.getElementById('eventModalBody').innerHTML = `
        <p><strong class="text-white">Platform:</strong>
            <span class="badge ms-1" style="background:${ev.color}">${ev.platform || 'Unknown'}</span></p>
        <p><strong class="text-white">Status:</strong> ${ev.status}</p>
        <p><strong class="text-white">Date:</strong> ${new Date(ev.start).toLocaleString()}</p>
    `;
    new bootstrap.Modal(document.getElementById('eventModal')).show();
}

renderCalendar(currentDate.getFullYear(), currentDate.getMonth() + 1);
</script>
@endsection
