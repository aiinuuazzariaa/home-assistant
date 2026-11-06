# IoT Data Integrator — Agent Instructions

## Project

PHP application using PostgreSQL and Home Assistant.

Read CLAUDE.md for the documented architecture.
Verify documentation against source code.

## Research Objectives

RQ1: Sensor data quality.
RQ2: Missing values and anomaly detection.
RQ3: REST API vs WebSocket ingestion.
RQ4: Performance and usability.

## Development Rules

1. Preserve the existing PHP architecture.
2. Inspect existing services before adding new ones.
3. Preserve original sensor values and JSON payloads.
4. Keep source timestamps separate from ingestion times.
5. Distinguish missing records, NULL, unknown,
   unavailable, and zero.
6. Do not modify the database without approval.
7. Do not run live scheduler jobs without approval.
8. Never expose credentials or API tokens.
9. Add PHPUnit tests for verified bug fixes.
10. Do not fabricate research results.

## Current Priority

Audit the existing implementation and identify
the first minimal, testable improvement.

## Required Workflow

1. Inspect relevant files.
2. Report evidence and risks.
3. Propose minimal changes.
4. Wait for approval.
5. Implement and test.
6. Document actual results.
