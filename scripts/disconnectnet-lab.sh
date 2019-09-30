#!/bin/bash
#
# This script was created by Florent Nolot
# (c) URCA, 2019
#

set -e

ENV_FILE=".env"

if [ -f ../${ENV_FILE} ]; then
     source ../${ENV_FILE}
else
     echo "Error: Environment file .env not found in ${ENV_FILE}. Please check this file exists and try again." >&2
     exit 1
fi

SCRIPT=$(readlink -f "$0")
SCRIPT_DIR=$(dirname "${SCRIPT}")
WORKER_DIR="${SCRIPT_DIR}/.."


usage() {
    echo "Usage: $0 [-f <Labfile>] [<xml>]" 1>&2; exit 1;
}

while getopts "f:" OPTION; do
    case ${OPTION} in
        f)
            if ! [ -f "${OPTARG}" ]; then
                echo 'Error: specified lab file not found.' >&2
                exit 1
            fi
            LAB_CONTENT="$(cat "${OPTARG}")"
            ;;
        *)
            ;;
    esac
done

if [ -z "${LAB_CONTENT}" ]; then
    # Relies on for loop, which is looping on args by default
    for CONTENT; do true; done
    LAB_CONTENT="${CONTENT}"
fi

xml() {
    xmllint --xpath "string($1)" - <<EOF
$LAB_CONTENT
EOF
}

LAB_NAME=$(xml /lab/@name)
BRIDGE_UUID=$(xml "/lab/instance/@uuid")
BRIDGE_NAME="br-$(echo ${BRIDGE_UUID} | cut -c-8)"
#Create patch between lab's OVS and Worker's OVS
ovs-vsctl del-port patch-ovs-"${BRIDGE_NAME}-0"
ovs-vsctl del-port patch-ovs0-"${BRIDGE_NAME}"
#Create new routing table for packet from the network of lab's device
sudo ip rule del from "${LAB_NETWORK}" lookup 4
#Add default route to the data gateway
sudo ip route del "${DATA_NETWORK}" dev "${BRIDGE_INT}" table 4
sudo ip route del default via "${BRIDGE_INT_GW}" table 4
sudo /sbin/iptables -t nat -D POSTROUTING -s ${LAB_NETWORK} -o ${BRIDGE_INT} -j MASQUERADE

echo "BRIDGE_UUID".$BRIDGE_UUID."\n";
echo "BRIDGE_NAME".$BRIDGE_NAME."\n";
echo "OK";
exit 0
