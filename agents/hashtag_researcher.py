"""
Hashtag Researcher Agent — hybrid approach: local DB + occasional LLM + SerpAPI.
Keeps a cached database of healthcare hashtags, refreshes weekly.
"""

from __future__ import annotations

import json
from datetime import datetime, timedelta
from typing import Optional

from sqlalchemy import select, func
from sqlalchemy.ext.asyncio import AsyncSession

from memory.models import HashtagCache, ContentCategory, Platform
from agents.schemas import CaptionResult
from config.settings import get_settings


# ── Pre-built hashtag database (seeds the MySQL table on first run) ────────────

SEED_HASHTAGS: dict[str, dict[str, list[str]]] = {
    "opd": {
        "instagram": [
            "healthcare", "doctor", "clinic", "checkup", "healthcheckup",
            "medicalcare", "doctorvisit", "wellness", "healthylifestyle",
            "preventivecare", "familydoctor", "healthclinic", "OPD",
            "consultation", "healthmatters", "digitalhealth",
        ],
        "facebook": ["healthcare", "clinic", "doctor", "healthcheckup", "wellness"],
        "linkedin": ["healthcare", "medicalservices", "primarycare", "healthtech", "clinicmanagement"],
        "tiktok": ["doctor", "clinic", "healthcheck", "wellness", "healthcare"],
        "youtube": [
            "doctor visit", "health checkup", "OPD", "clinic tour",
            "medical consultation", "healthcare", "health tips",
        ],
    },
    "laboratory": {
        "instagram": [
            "labtest", "bloodtest", "healthtest", "diagnostics", "laboratory",
            "CBC", "thyroid", "cliniclab", "pathology", "healthscreening",
            "medicallab", "bloodwork", "healthawareness", "labreport",
        ],
        "facebook": ["labtest", "bloodtest", "diagnostics", "healthscreening", "laboratory"],
        "linkedin": ["diagnostics", "pathology", "clinicallaboratory", "medtech", "healthscreening"],
        "tiktok": ["labtest", "bloodtest", "healthcheck", "diagnostics", "medical"],
        "youtube": [
            "blood test", "lab test", "health screening", "diagnostics",
            "pathology", "health checkup", "medical tests explained",
        ],
    },
    "hydrafacial": {
        "instagram": [
            "hydrafacial", "skincare", "glowingskin", "facialtreatment", "clearskin",
            "skincareroutine", "beautytreatment", "dermatology", "skinclinic",
            "glowup", "hydrafaciallove", "skinhealth", "facialist", "skingoals",
            "hydration", "deepcleansing", "skincaregoals", "beautycare",
        ],
        "facebook": ["hydrafacial", "skincare", "facialtreatment", "glowingskin", "beautytreatment"],
        "linkedin": ["aestheticmedicine", "skincare", "dermatology", "beautyindustry", "medicalesthetics"],
        "tiktok": ["hydrafacial", "skincare", "glowup", "beforeandafter", "skintok"],
        "youtube": [
            "hydrafacial", "facial treatment", "skincare routine", "skin clinic",
            "glowing skin", "before and after", "skin treatment",
        ],
    },
    "laser_hair_removal": {
        "instagram": [
            "laserhairremoval", "hairremoval", "smoothskin", "lasertreatment",
            "painlesshairremoval", "permanenthairremoval", "skincare",
            "beautytreatment", "dermatology", "hairfree", "laserclinic",
            "bodycare", "laserskincare", "grooming",
        ],
        "facebook": ["laserhairremoval", "hairremoval", "smoothskin", "beautytreatment", "skincare"],
        "linkedin": ["aestheticmedicine", "lasertreatment", "dermatology", "beautytech", "medicalesthetics"],
        "tiktok": ["laserhairremoval", "smoothskin", "hairfree", "beautytreatment", "skintok"],
        "youtube": [
            "laser hair removal", "permanent hair removal", "painless laser",
            "hair removal treatment", "beauty treatment", "laser clinic",
        ],
    },
    "xray": {
        "instagram": [
            "xray", "digitalxray", "diagnosticimaging", "radiology",
            "healthcare", "medicalimaging", "bonehealth", "healthdiagnostics",
        ],
        "facebook": ["xray", "diagnosticimaging", "healthcare", "radiology", "medicalimaging"],
        "linkedin": ["radiology", "diagnosticimaging", "medicalimaging", "healthtech", "healthcare"],
        "tiktok": ["xray", "medical", "healthcare", "radiology"],
        "youtube": ["x-ray", "diagnostic imaging", "radiology", "medical imaging", "healthcare"],
    },
    "ultrasound_echo": {
        "instagram": [
            "ultrasound", "echocardiography", "echo", "heartcheck", "cardiology",
            "diagnosticimaging", "hearthealth", "cardiac", "healthscreening",
            "medicalimaging", "heartcare",
        ],
        "facebook": ["ultrasound", "echo", "heartcheck", "cardiology", "hearthealth"],
        "linkedin": ["echocardiography", "cardiology", "diagnosticimaging", "hearthealth", "medicaltech"],
        "tiktok": ["heartcheck", "ultrasound", "echo", "cardiology", "health"],
        "youtube": [
            "echocardiography", "echo test", "heart ultrasound", "cardiac test",
            "heart health", "cardiology", "heart screening",
        ],
    },
    "ecg": {
        "instagram": [
            "ECG", "hearttest", "cardiology", "hearthealth", "heartmonitor",
            "cardiaccare", "preventivehealth", "heartscreening", "healthcare",
        ],
        "facebook": ["ECG", "hearttest", "cardiology", "hearthealth", "healthscreening"],
        "linkedin": ["ECG", "cardiology", "cardiacdiagnostics", "hearthealth", "medicaldevices"],
        "tiktok": ["ECG", "heartcheck", "health", "cardiology"],
        "youtube": ["ECG test", "heart test", "electrocardiogram", "heart health", "cardiac screening"],
    },
    "pharmacy": {
        "instagram": [
            "pharmacy", "medication", "healthcare", "prescription", "wellness",
            "healthtips", "pharma", "affordablemedicine", "drugstore",
        ],
        "facebook": ["pharmacy", "medication", "healthcare", "wellness", "affordablemedicine"],
        "linkedin": ["pharmacy", "pharmaceuticals", "healthcare", "pharmatech", "medication"],
        "tiktok": ["pharmacy", "medicine", "health", "wellness"],
        "youtube": ["pharmacy", "medication", "health tips", "affordable medicine", "wellness"],
    },
    "team": {
        "instagram": [
            "teamwork", "healthcareteam", "doctors", "nurses", "medicalstaff",
            "meettheteam", "healthcareheroes", "cliniclife", "dedication",
        ],
        "facebook": ["team", "healthcareteam", "meettheteam", "doctors", "cliniclife"],
        "linkedin": ["teamwork", "healthcareprofessionals", "hiring", "clinicculture", "medicalteam"],
        "tiktok": ["teamwork", "doctorsoftiktok", "healthcareworkers", "behindthescenes"],
        "youtube": ["healthcare team", "meet the team", "clinic team", "doctors", "medical staff"],
    },
    "before_after": {
        "instagram": [
            "beforeandafter", "transformation", "results", "skincare",
            "beautytransformation", "realresults", "glow", "treatmentresults",
        ],
        "facebook": ["beforeandafter", "transformation", "results", "realresults"],
        "linkedin": ["results", "transformation", "casestudy", "medicalresults"],
        "tiktok": ["beforeandafter", "glow", "transformation", "results", "skincare"],
        "youtube": ["before and after", "transformation", "treatment results", "real results"],
    },
    "promotional": {
        "instagram": [
            "specialoffer", "discount", "healthdeal", "limitedoffer",
            "healthpackage", "healthcare", "promotion", "deal", "savebig",
        ],
        "facebook": ["specialoffer", "discount", "healthpackage", "limitedoffer", "promotion"],
        "linkedin": ["healthcareservices", "corporatehealth", "wellness", "healthpackage"],
        "tiktok": ["deal", "offer", "healthcare", "discount", "healthtok"],
        "youtube": ["special offer", "health package", "discount", "healthcare deal"],
    },
    "job_posting": {
        "instagram": [
            "hiring", "jobopening", "healthcarejobs", "joinourteam",
            "careers", "nowhiring", "jobsearch", "medicalcareers",
        ],
        "facebook": ["hiring", "jobopening", "joinourteam", "nowhiring", "healthcarejobs"],
        "linkedin": [
            "hiring", "jobopening", "healthcarecareers", "nowhiring",
            "vacancy", "recruitment", "talent",
        ],
        "tiktok": ["hiring", "jobopening", "jobtok", "careers", "healthcarejobs"],
        "youtube": ["hiring", "job opening", "healthcare careers", "join our team"],
    },
    "general": {
        "instagram": [
            "health", "healthcare", "wellness", "clinic",
            "healthtips", "healthylifestyle", "medical", "care",
        ],
        "facebook": ["health", "healthcare", "wellness", "clinic", "healthtips"],
        "linkedin": ["healthcare", "health", "wellness", "medical", "clinicmanagement"],
        "tiktok": ["health", "healthcare", "wellness", "healthtok", "medical"],
        "youtube": ["health", "healthcare", "wellness", "health tips", "medical"],
    },
    "facility": {
        "instagram": [
            "clinic", "medicalfacility", "modernhealthcare", "cleanclinic",
            "healthcarefacility", "newequipment", "healthtech",
        ],
        "facebook": ["clinic", "medicalfacility", "healthcare", "modernhealthcare"],
        "linkedin": ["healthcarefacility", "medicalinfrastructure", "healthtech", "clinicdesign"],
        "tiktok": ["clinic", "clintour", "healthcare", "medicalfacility"],
        "youtube": ["clinic tour", "medical facility", "healthcare", "modern clinic"],
    },
    "patient_testimonial": {
        "instagram": [
            "patientreview", "testimonial", "happypatient", "healthcare",
            "patientcare", "trustedcare", "review", "satisfied",
        ],
        "facebook": ["testimonial", "patientreview", "happypatient", "healthcare", "trustedcare"],
        "linkedin": ["patientexperience", "healthcare", "testimonial", "patientcare"],
        "tiktok": ["review", "testimonial", "patient", "healthcare"],
        "youtube": ["patient review", "testimonial", "patient experience", "healthcare review"],
    },
}


async def seed_hashtags(session: AsyncSession) -> None:
    """Populate the hashtag_cache table if empty."""
    count = await session.scalar(select(func.count()).select_from(HashtagCache))
    if count and count > 0:
        return  # Already seeded

    for category_str, platforms in SEED_HASHTAGS.items():
        try:
            category = ContentCategory(category_str)
        except ValueError:
            continue
        for platform_str, tags in platforms.items():
            try:
                platform = Platform(platform_str)
            except ValueError:
                continue
            for i, tag in enumerate(tags):
                entry = HashtagCache(
                    category=category,
                    platform=platform,
                    hashtag=tag,
                    relevance_score=max(0.5, 1.0 - i * 0.03),  # Earlier = more relevant
                )
                session.add(entry)

    await session.commit()


async def get_hashtags(
    session: AsyncSession,
    category: str,
    platform: str,
    max_count: int = 15,
) -> list[str]:
    """Get the best hashtags for a category + platform from the cache."""
    try:
        cat = ContentCategory(category)
        plat = Platform(platform)
    except ValueError:
        cat = ContentCategory.GENERAL
        plat = Platform(platform) if platform in Platform.__members__.values() else Platform.INSTAGRAM

    result = await session.execute(
        select(HashtagCache.hashtag)
        .where(HashtagCache.category == cat, HashtagCache.platform == plat)
        .order_by(HashtagCache.relevance_score.desc())
        .limit(max_count)
    )
    tags = [row[0] for row in result.fetchall()]

    return tags[:max_count]


async def update_trending_hashtags(session: AsyncSession) -> None:
    """
    Refresh trending hashtags using SerpAPI (Google Trends).
    Call this weekly via a scheduled task.
    """
    settings = get_settings()
    if not settings.serpapi_api_key:
        return  # Skip if no API key

    try:
        from serpapi import GoogleSearch

        # Search for trending healthcare hashtags
        queries = [
            "healthcare clinic hashtags trending",
            "skincare treatment hashtags",
            "medical services social media hashtags",
        ]

        for query in queries:
            search = GoogleSearch({
                "q": query,
                "api_key": settings.serpapi_api_key,
                "num": 5,
            })
            # Parse results and extract potential hashtags
            # (This is a simplified implementation — in production you'd
            # parse more carefully and update relevance scores)
            results = search.get_dict()
            # Process organic results for hashtag extraction
            for result in results.get("organic_results", [])[:3]:
                snippet = result.get("snippet", "")
                # Extract words that look like hashtags from snippets
                words = snippet.split()
                for word in words:
                    cleaned = word.strip("#@.,!?()[]").lower()
                    if len(cleaned) > 3 and cleaned.isalpha():
                        # Check if it's already in the DB
                        existing = await session.scalar(
                            select(func.count())
                            .select_from(HashtagCache)
                            .where(HashtagCache.hashtag == cleaned)
                        )
                        if not existing:
                            # Add as a general trending hashtag
                            for plat in Platform:
                                entry = HashtagCache(
                                    category=ContentCategory.GENERAL,
                                    platform=plat,
                                    hashtag=cleaned,
                                    relevance_score=0.7,
                                    is_trending=True,
                                )
                                session.add(entry)

        await session.commit()

    except Exception:
        pass  # Non-critical — just skip trending update
