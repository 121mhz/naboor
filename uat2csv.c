//
// Copyright 2015, Daniel Katz-Braunschweig <Dkatz@dataixl.com>
//    with portions Copyright by Oliver Jowett <oliver@mutability.co.uk>
//

// This file is free software: you may copy, redistribute and/or modify it  
// under the terms of the GNU General Public License as published by the
// Free Software Foundation, either version 2 of the License, or (at your  
// option) any later version.  
//
// This file is distributed in the hope that it will be useful, but  
// WITHOUT ANY WARRANTY; without even the implied warranty of  
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU  
// General Public License for more details.
//
// You should have received a copy of the GNU General Public License  
// along with this program.  If not, see <http://www.gnu.org/licenses/>.

#include <stdio.h>
#include <string.h>

#include "uat.h"
#include "uat_decode.h"
#include "reader.h"

void uat_getCSVfrom_adsb_mdb(const struct uat_adsb_mdb *mdb, FILE *to)
{
        //Dan's Code
        char addr[100]="UNKNOWN";
        char lat[100]="UNKNOWN";
        char lon[100]="UNKNOWN";
        char altitude[100]="UNKNOWN";
        char altType[10]="UNKNOWN"; //Geo or Baro
        char heading[100]="UNKNOWN";
        char headingType[100]="UNKNOWN"; //track, mag or true
        char speed[100]="UNKNOWN";
        char vertRate[100]="UNKNOWN";
        char vertRateType[10]="UNKNOWN"; //geo or baro
	char callsign[100]="UNKNOWN";

        if(!mdb->has_sv || !mdb->position_valid)
                return; // Nothing to do with this message
        sprintf(addr,"%06X",mdb->address);
        sprintf(lat,"%+.6f",mdb->lat);
        sprintf(lat,"%+.6f",mdb->lat);
        sprintf(lon,"%+.6f",mdb->lon);
        if (mdb->altitude_type==ALT_BARO){
                strcpy(altType,"BARO");
                sprintf(altitude,"%d",mdb->altitude);
        }
        else if(mdb->altitude_type==ALT_GEO){
                strcpy(altType,"GEO");
                sprintf(altitude,"%d",mdb->altitude);
        }

        if (mdb->track_type==TT_TRACK){
                strcpy(headingType,"TRACK");
                sprintf(heading,"%u", mdb->track);
        }else if (mdb->track_type==TT_MAG_HEADING){
                strcpy(headingType,"MAG");
                sprintf(heading,"%u", mdb->track);
        }else if (mdb->track_type==TT_TRUE_HEADING){
                strcpy(headingType,"TRUE");
                sprintf(heading,"%u", mdb->track);
        }
        if (mdb->speed_valid)
                sprintf(speed,"%u",mdb->speed);
        if (mdb->vert_rate_source==ALT_BARO){
                strcpy(vertRateType,"BARO");
                sprintf(vertRate,"%d",mdb->vert_rate);  
        }else if (mdb->vert_rate_source==ALT_GEO){
                strcpy(vertRateType,"GEO");
                sprintf(vertRate,"%d",mdb->vert_rate);  
        }
	strcpy(callsign, (mdb->callsign_type == CS_INVALID ? "unavailable" : mdb->callsign));
        //addr,lat,lon,alt,altType,heading,headingType,speed,vertRate,vertRateType,Callsign
        fprintf(to,"%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",addr,lat,lon,altitude,altType,heading,headingType,speed,vertRate,vertRateType,callsign);
}



void handle_frame(frame_type_t type, uint8_t *frame, int len, void *extra)
{
    if (type == UAT_DOWNLINK) {
        struct uat_adsb_mdb mdb;
        uat_decode_adsb_mdb(frame, &mdb);
        uat_getCSVfrom_adsb_mdb(&mdb, stdout);
    }

    //fprintf(stdout, "\n");
    fflush(stdout);
}

int main(int argc, char **argv)
{
    struct dump978_reader *reader;
    int framecount;

    reader = dump978_reader_new(0,0);
    if (!reader) {
        perror("dump978_reader_new");
        return 1;
    }
    
    while ((framecount = dump978_read_frames(reader, handle_frame, NULL)) > 0)
        ;

    if (framecount < 0) {
        perror("dump978_read_frames");
        return 1;
    }

    return 0;
}

