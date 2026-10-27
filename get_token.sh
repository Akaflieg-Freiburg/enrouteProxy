#!/bin/bash
# Fetches a bearer token from the FAA NMS auth endpoint by hand.
# Uses NMS_AUTH_URL, NMS_CLIENT_ID and NMS_CLIENT_SECRET from the environment.

curl -X POST --location "${NMS_AUTH_URL:?NMS_AUTH_URL is not set}" -d grant_type=client_credentials -u "${NMS_CLIENT_ID:?NMS_CLIENT_ID is not set}:${NMS_CLIENT_SECRET:?NMS_CLIENT_SECRET is not set}"
