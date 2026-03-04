"""
Pydantic schemas used across agents for structured data exchange.
These are NOT database models — they're lightweight DTOs for inter-agent communication.
"""

from __future__ import annotations

from datetime import datetime
from typing import Optional

from pydantic import BaseModel, Field


class MediaAnalysis(BaseModel):
    """Output from the Vision Analyzer agent."""

    content_type: str = Field(
        description="What the media shows: facility, treatment, equipment, team, patient, product, etc."
    )
    content_category: str = Field(
        description="One of: opd, laboratory, hydrafacial, laser_hair_removal, xray, "
        "ultrasound_echo, ecg, pharmacy, team, facility, patient_testimonial, "
        "before_after, promotional, general"
    )
    mood: str = Field(description="Overall mood: professional, warm, clinical, fun, inspiring, etc.")
    quality_score: float = Field(
        description="Image/video quality from 0-10. Consider lighting, focus, composition."
    )
    description: str = Field(description="1-2 sentence description of what the content shows.")
    suggested_platforms: list[str] = Field(
        description="Ranked list of best platforms for this content."
    )
    improvement_tips: list[str] = Field(
        description="Suggestions to improve the media (brightness, cropping, etc.)"
    )
    people_detected: bool = Field(description="Whether people/faces are visible.")
    text_detected: str = Field(
        description="Any text visible in the image/video. Empty string if none."
    )
    is_before_after: bool = Field(
        description="Whether this looks like a before/after comparison."
    )
    healthcare_services: list[str] = Field(
        description="Which business services are related to this content."
    )
    safety_assessment: Optional[dict] = Field(
        default=None,
        description="Safety assessment with is_safe (bool), concerns (list), brand_appropriate (bool)."
    )


class PlatformSpec(BaseModel):
    """Specifications for a target platform."""

    platform: str
    width: int
    height: int
    max_duration_seconds: Optional[float] = None
    max_file_size_mb: Optional[float] = None
    aspect_ratio: str
    format: str = "mp4"
    caption_max_length: Optional[int] = None
    supports_hashtags: bool = True
    max_hashtags: int = 30


class EditInstructions(BaseModel):
    """Instructions for the media editor agent."""

    brightness_adjustment: float = Field(
        default=1.0, description="Multiplier: 1.0=no change, >1=brighter, <1=darker"
    )
    contrast_adjustment: float = Field(default=1.0, description="Multiplier for contrast.")
    saturation_adjustment: float = Field(default=1.0, description="Multiplier for saturation.")
    crop_to_aspect: Optional[str] = Field(
        default=None, description="Target aspect ratio like '1:1', '9:16', '16:9'"
    )
    resize_to: Optional[tuple[int, int]] = Field(
        default=None, description="Target (width, height)"
    )
    add_watermark: bool = True
    remove_background: bool = False
    add_text_overlay: Optional[str] = Field(
        default=None, description="Text to overlay on the image."
    )
    # Background music (video only)
    background_music_path: Optional[str] = Field(
        default=None, description="Path to a royalty-free MP3 to mix as background music."
    )
    music_volume: float = Field(
        default=0.2, description="Music volume (0.0-1.0) relative to original audio."
    )
    music_fade_in: float = Field(default=1.0, description="Fade-in duration in seconds.")
    music_fade_out: float = Field(default=2.0, description="Fade-out duration in seconds.")


class CaptionRequest(BaseModel):
    """Input for the caption writer agent."""

    platform: str
    content_description: str
    content_category: str
    mood: str
    healthcare_services: list[str] = []
    is_promotional: bool = False
    promotional_details: Optional[str] = None
    call_to_action: str = "Book an appointment today!"


class CaptionResult(BaseModel):
    """Output from the caption writer agent."""

    caption: str
    hashtags: list[str]
    title: Optional[str] = None  # For YouTube
    description: Optional[str] = None  # For YouTube


class JobRequest(BaseModel):
    """Input for creating a job posting."""

    title: str
    department: str
    experience_required: str = ""
    key_skills: list[str] = []
    salary_range: str = ""
    additional_notes: str = ""


class ResumeAnalysis(BaseModel):
    """Output from the resume screening agent."""

    candidate_name: str
    email: Optional[str] = None
    phone: Optional[str] = None
    education: list[str] = []
    experience_years: float = 0
    skills: list[str] = []
    previous_roles: list[str] = []
    match_score: float = Field(
        description="0-100 score of how well this candidate matches the job."
    )
    strengths: list[str] = []
    weaknesses: list[str] = []
    summary: str = Field(description="2-3 sentence AI summary of the candidate.")


class PackageProposal(BaseModel):
    """AI-proposed promotional package."""

    name: str
    tagline: str
    services_included: list[str]
    discount_details: str
    target_audience: str
    occasion: Optional[str] = None
    suggested_price: Optional[str] = None
    content_ideas: list[str] = Field(
        description="Suggested social media content ideas for promoting this package."
    )


class WorkflowState(BaseModel):
    """Shared state passed through the LangGraph orchestrator."""

    # Input
    media_item_id: int
    file_path: str
    media_type: str  # photo or video
    width: int = 0
    height: int = 0
    duration_seconds: float = 0

    # Analysis
    analysis: Optional[MediaAnalysis] = None

    # Routing
    target_platforms: list[str] = []

    # Per-platform outputs (keyed by platform name)
    edited_files: dict[str, str] = {}  # platform -> edited file path
    captions: dict[str, CaptionResult] = {}  # platform -> caption

    # Approval
    approved_platforms: list[str] = []
    denied_platforms: list[str] = []

    # Music
    music_tracks: dict[str, dict] = {}  # platform -> {title, path, mood}

    # Publishing
    published_platforms: dict[str, str] = {}  # platform -> post URL

    # Workflow control
    current_step: str = "analyze"
    error: Optional[str] = None
    workflow_thread_id: str = ""
