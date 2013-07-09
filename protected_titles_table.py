#!/usr/bin/env python

# Released into the public domain
# By Legoktm & Uncyclopedia development team, 2013

import oursql
import mw
import settings
uncy = mw.Wiki(settings.api_url)


def gen():
    go_on = True
    params = {'action': 'query',
              'list': 'protectedtitles',
              'ptlimit': 'max',
              'ptprop': 'timestamp|user|comment|expiry|level',
              }
    while go_on:
        req = uncy.request(params)
        print 'fetched'
#        print type(req['query']['blocks'])
        yield req['query']['protectedtitles']
        if 'query-continue' in req:
            params['ptstart'] = req['query-continue']['protectedtitles']['ptstart']
            print params['ptstart']
        else:
            go_on = False


def insert():
    print 'Populating the protected_titles table...'
    conn = oursql.connect(host=settings.db_host, user=settings.db_user, passwd=settings.db_pass,
                          db=settings.db_name)
    cur = conn.cursor()
    cur.executemany('INSERT INTO `protected_titles` VALUES (?,?,?,?,?,?,?);', parse(gen()))
    conn.close()


def parse(pts):
    for ptset in pts:
        for pt in ptset:
            print pt
            x=list()
            x.append(int(pt['ns']))
            #sanitize the title
            if not int(pt['ns']):
                x.append(pt['title'])
            else:
                x.append(pt['title'].split(':',1)[1])
            x.append(int(pt['userid']))
            x.append(pt['comment'])
            x.append(convert_ts(pt['timestamp']))
            if pt['expiry'] == 'infinity':
                x.append('infinity')
            else:
                x.append(convert_ts(pt['expiry']))
            x.append(pt['level'])
            yield tuple(x)


def convert_ts(i):
    #2013-01-05T01:16:52Z
    output = i.replace('-','').replace('T','').replace(':','').replace('Z','')
    return output

if __name__ == "__main__":
    insert()
