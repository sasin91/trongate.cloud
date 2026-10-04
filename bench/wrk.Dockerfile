# Load generator for the cluster benchmark
FROM alpine:3.22
RUN apk add --no-cache wrk
COPY bench/users.lua /scripts/users.lua
USER 65534:65534
