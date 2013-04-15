#!/usr/bin/env python

# Released into the public domain
# By Legoktm & Uncyclopedia development team, 2013

import oursql
import settings
import mw
uncy = mw.Wiki(settings.api_url)


def gen():
    go_on = True
    params = {'action':'query',
              'list':'blocks',
              'bklimit':'max',
              'bkprop':'id|user|userid|by|byid|timestamp|expiry|reason|range|flags',
              }
    while go_on:
        req=uncy.request(params)
        print 'fetched'
        yield req['query']['blocks']
        if req.has_key('query-continue'):
            params['bkstart'] = req['query-continue']['blocks']['bkstart']
            print params['bkstart']
        else:
            go_on = False


def insert():
    print 'Populating the ipblocks table....'
    conn = oursql.connect(host=settings.db_host, user=settings.db_pass, passwd=settings.db_pass,
                          db=settings.db_name)
    cur = conn.cursor()
    cur.executemany('INSERT INTO `ipblocks` VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?);', parse(gen()))
    conn.close()


def parse(blocks):
    for blockset in blocks:
        for block in blockset:
            print block
            x = list()
            if block['reason'].startswith('Autoblocked because your IP address'):
                continue
            x.append(int(block['id']))
            x.append(block['user'])
            x.append(int(block['userid']))
            x.append(int(block['byid']))
            x.append(block['by'])
            x.append(block['reason'])
            x.append(convert_ts(block['timestamp']))
            x.append(0)  # ipb_auto
            x.append(int(block.has_key('anononly')))
            x.append(int(block.has_key('nocreate')))
            x.append(int(block.has_key('autoblock')))
            if block['expiry'] == 'infinity':
                x.append('infinity')
            else:
                x.append(convert_ts(block['expiry']))
            x.append(block['rangestart'])
            x.append(block['rangeend'])
            x.append(0)  # ipb_deleted
            x.append(int(block.has_key('noemail')))
            x.append(int(not block.has_key('allowusertalk')))
            x.append(None)
            yield tuple(x)



def convert_ts(i):
    output = i.replace('-','').replace('T','').replace(':','').replace('Z','')
    return output

if __name__ == "__main__":
    insert()
