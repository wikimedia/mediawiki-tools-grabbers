#!/usr/bin/env python

# Released into the public domain
# By Legoktm & Uncyclopedia development team, 2013

import oursql
import settings
import mw
uncy = mw.Wiki(settings.api_url)


def gen(ns=0):
    #see full_gen() below
    go_on = True
    params = {'action': 'query',
              'generator': 'allpages',
              'gaplimit': 'max',
              'gapprtype': 'edit|move',
              'gapprlevel': 'sysop|autoconfirmed',
              'gapnamespace': ns,
              'prop': 'info',
              'inprop': 'protection',
              }
    while go_on:
        req = uncy.request(params)
        print 'fetched'
#        print type(req['query']['blocks'])
        if 'query' in req:
            yield req['query']['pages']
        if 'query-continue' in req:
            print req['query-continue']
            for key in req['query-continue']['allpages'].keys():
                params[key] = req['query-continue']['allpages'][key]
        else:
            go_on = False


def insert():
    print 'Populating the page_restrictions table...'
    conn = oursql.connect(host=settings.db_host, user=settings.db_user, passwd=settings.db_pass,
                          db=settings.db_name)
    cur = conn.cursor()
    cur.executemany('INSERT IGNORE INTO `page_restrictions` VALUES (?,?,?,?,?,?,?);', parse(full_gen()))
    conn.close()


def parse(pts):
    for group in pts:
        for id in group.keys():
            print group[id]
            for prot in group[id]['protection']:
                x=list()
                x.append(int(id))
                x.append(prot['type'])
                x.append(prot['level'])
                x.append(int(prot.has_key('cascade')))
                x.append(None)  # pr_user
                if prot['expiry'] == 'infinity':
                    x.append(None)
                else:
                    x.append(convert_ts(prot['expiry']))
                x.append(None)  # pr_id
                yield tuple(x)


def convert_ts(i):
    #2013-01-05T01:16:52Z
    output = i.replace('-','').replace('T','').replace(':','').replace('Z','')
    return output


def full_gen():
    #wrapper for gen() above
    #https://en.wikipedia.org/w/api.php?action=query&meta=siteinfo&siprop=namespaces&format=jsonfm
    params = {'action':'query',
              'meta':'siteinfo',
              'siprop':'namespaces',
              }
    req = uncy.request(params)
    for key in req['query']['namespaces'].keys():
        if int(key) >= 0:
            for item in gen(key):
                yield item

if __name__ == "__main__":
    insert()
