"""Logging setup shared by all Reflection modules."""

import logging


def configure_logging():
    """Configure the default logging format for the agent process."""
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s - [%(levelname)s] - %(message)s",
    )
