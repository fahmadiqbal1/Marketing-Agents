"""
Event Bus — simple async pub/sub for real-time notifications.

Components emit events; SSE endpoint streams them to the dashboard.
Uses asyncio.Queue per connected client for fan-out.
"""

from __future__ import annotations

import asyncio
import json
import logging
import time
from typing import Any

logger = logging.getLogger(__name__)

# All connected SSE clients (one queue per client)
_subscribers: list[asyncio.Queue] = []
_lock = asyncio.Lock()

# Recent events buffer (for clients that just connected)
_recent_events: list[dict] = []
_MAX_RECENT = 50


async def emit(event_type: str, data: dict[str, Any] | None = None):
    """Emit an event to all connected SSE clients."""
    event = {
        "type": event_type,
        "data": data or {},
        "timestamp": time.time(),
    }

    # Add to recent buffer
    _recent_events.append(event)
    if len(_recent_events) > _MAX_RECENT:
        _recent_events.pop(0)

    # Fan out to all subscribers
    async with _lock:
        dead = []
        for q in _subscribers:
            try:
                q.put_nowait(event)
            except asyncio.QueueFull:
                dead.append(q)

        # Remove dead/full queues
        for q in dead:
            _subscribers.remove(q)

    logger.debug(f"Event emitted: {event_type} → {len(_subscribers)} clients")


async def subscribe() -> asyncio.Queue:
    """Subscribe to events. Returns a queue that receives events."""
    q: asyncio.Queue = asyncio.Queue(maxsize=100)

    # Send recent events to catch up
    for event in _recent_events[-10:]:
        q.put_nowait(event)

    async with _lock:
        _subscribers.append(q)

    return q


async def unsubscribe(q: asyncio.Queue):
    """Remove a subscriber queue."""
    async with _lock:
        if q in _subscribers:
            _subscribers.remove(q)


def get_recent_events(count: int = 20) -> list[dict]:
    """Get the N most recent events (for initial page load)."""
    return _recent_events[-count:]
