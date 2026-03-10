#!/bin/bash

curl -X POST --location https://api-staging.cgifederal-aim.com/v1/auth/token -d grant_type=client_credentials -u $NMS_CLIENT_ID:$NMS_CLIENT_SECRET
