"""Runtime configuration for the Reflection farm agent."""

import socket

VERSION = "1.0.0"
SERVER_URL = "http://your-server-domain.com/farm_api.php"  # Target PHP endpoint
POLL_INTERVAL = 10  # Seconds to wait before checking for new jobs if idle
PC_ID = socket.gethostname()  # Unique identifier for this node
